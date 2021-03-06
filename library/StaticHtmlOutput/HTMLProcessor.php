<?php
// TODO: rewerite to be one loop of all elements,
// applying multiple transformations at once per link, reducing iterations
// TODO: deal with inline CSS blocks or style attributes on tags
// TODO: don't rewrite mailto links unless specified, re #30
class HTMLProcessor {

    public function processHTML(
        $html_document,
        $page_url,
        $wp_site_env,
        $new_paths,
        $wp_site_url,
        $baseUrl,
        $allowOfflineUsage,
        $useRelativeURLs,
        $useBaseHref
        ) {

        // instantiate the XML body here
        $this->xml_doc = new DOMDocument();
        $this->raw_html = $html_document;
        $this->page_url = $page_url;
        $this->wp_site_env = $wp_site_env;
        $this->new_paths = $new_paths;
        $this->wp_site_url = $wp_site_url;
        $this->baseUrl = $baseUrl;
        $this->allowOfflineUsage = $allowOfflineUsage;
        $this->useRelativeURLs = $useRelativeURLs;
        $this->useBaseHref = $useBaseHref;

        $this->base_tag_exists = false;

        require_once dirname( __FILE__ ) . '/../URL2/URL2.php';
        $this->page_url = new Net_URL2( $page_url );

        $this->discoverNewURLs = (
            isset( $_POST['discoverNewURLs'] ) &&
             $_POST['discoverNewURLs'] == 1 &&
             $_POST['ajax_action'] === 'crawl_site'
        );

        $this->removeConditionalHeadComments = isset(
            $_POST['removeConditionalHeadComments']
        );

        $this->rewriteWPPaths = isset(
            $_POST['rewriteWPPaths']
        );

        $this->removeWPMeta = isset(
            $_POST['removeWPMeta']
        );

        $this->removeWPLinks = isset(
            $_POST['removeWPLinks']
        );

        $this->discovered_urls = [];

        $this->wp_uploads_path = $_POST['wp_uploads_path'];
        $this->working_directory =
            isset( $_POST['workingDirectory'] ) ?
            $_POST['workingDirectory'] :
            $this->wp_uploads_path;

        // PERF: 70% of function time
        // prevent warnings, via https://stackoverflow.com/a/9149241/1668057
        libxml_use_internal_errors( true );
        $this->xml_doc->loadHTML( $html_document );
        libxml_use_internal_errors( false );

        // start the full iterator here, along with copy of dom
        $elements = iterator_to_array(
            $this->xml_doc->getElementsByTagName( '*' )
        );

        foreach ( $elements as $element ) {
            switch ( $element->tagName ) {
                case 'meta':
                    $this->processMeta( $element );
                    break;
                case 'a':
                    $this->processAnchor( $element );
                    break;
                case 'img':
                    $this->processImage( $element );
                    break;
                case 'head':
                    $this->processHead( $element );
                    break;
                case 'link':
                    // NOTE: not to confuse with anchor element
                    $this->processLink( $element );
                    break;
                case 'script':
                    // can contain src=,
                    // can also contain URLs within scripts
                    // and escaped urls
                    $this->processScript( $element );
                    break;

                    // TODO: how about other places that can contain URLs
                    // data attr, reacty stuff, etc?
            }
        }

        // funcs to apply to whole page
        $this->detectEscapedSiteURLs();
        // $this->setBaseHref();
        $this->writeDiscoveredURLs();
    }

    public function processLink( $element ) {
        if ( $this->removeWPLinks ) {
            $relativeLinksToRemove = array(
                'shortlink',
                'canonical',
                'pingback',
                'alternate',
                'EditURI',
                'wlwmanifest',
                'index',
                'profile',
                'prev',
                'next',
                'wlwmanifest',
            );

            $link_rel = $element->getAttribute( 'rel' );

            if ( in_array( $link_rel, $relativeLinksToRemove ) ) {
                $element->parentNode->removeChild( $element );
            } elseif ( strpos( $link_rel, '.w.org' ) !== false ) {
                $element->parentNode->removeChild( $element );
            }
        }
    }

    public function addDiscoveredURL( $url ) {
        if ( $this->discoverNewURLs ) {
            if ( $this->isInternalLink( $url ) ) {
                $this->discovered_urls[] = $url;
            }
        }
    }

    public function processImage( $element ) {
        $this->normalizeURL( $element, 'src' );
        $this->removeQueryStringFromInternalLink( $element );
        $this->addDiscoveredURL( $element->getAttribute( 'src' ) );
        $this->rewriteWPPaths( $element );
        $this->rewriteBaseURL( $element );
    }

    public function processHead( $element ) {
        // $this->setBaseHref( $element, 'src' );
        $head_elements = iterator_to_array(
            $element->childNodes
        );

        foreach ( $head_elements as $node ) {
            if ( $node instanceof DOMComment ) {
                if ( $this->removeConditionalHeadComments ) {
                    $node->parentNode->removeChild( $node );
                }
            } elseif ( $node->tagName === 'base' ) {
                // as smaller iteration to run conditional against here
                $this->base_tag_exists = true;
            }
        }

        // TODO: optionally strip conditional comments from head
    }

    public function processScript( $element ) {
        $this->normalizeURL( $element, 'src' );
        $this->removeQueryStringFromInternalLink( $element );
        $this->addDiscoveredURL( $element->getAttribute( 'src' ) );
        $this->rewriteWPPaths( $element );
        $this->rewriteBaseURL( $element );
    }

    public function processAnchor( $element ) {
        $this->normalizeURL( $element, 'href' );
        $this->removeQueryStringFromInternalLink( $element );
        $this->addDiscoveredURL( $element->getAttribute( 'href' ) );
        $this->rewriteWPPaths( $element );
        $this->rewriteBaseURL( $element );
    }

    public function processMeta( $element ) {
        if ( $this->removeWPMeta ) {
            $meta_name = $element->getAttribute( 'name' );

            if ( strpos( $meta_name, 'generator' ) !== false ) {
                $element->parentNode->removeChild( $element );
            }
        }
    }

    public function writeDiscoveredURLs() {
        if ( ! $this->discoverNewURLs &&
            $_POST['ajax_action'] === 'crawl_site' ) {
            return;
        }

        file_put_contents(
            $this->working_directory . '/WP-STATIC-DISCOVERED-URLS',
            PHP_EOL .
                implode( PHP_EOL, array_unique( $this->discovered_urls ) ),
            FILE_APPEND | LOCK_EX
        );
    }

    // make link absolute, using current page to determine full path
    public function normalizeURL( $element, $attribute ) {
        $original_link = $element->getAttribute( $attribute );

        if ( $this->isInternalLink( $original_link ) ) {
            $abs = $this->page_url->resolve( $original_link );
            $element->setAttribute( $attribute, $abs );
        }
    }

    public function isInternalLink( $link ) {
        // TODO: apply only to links starting with .,..,/,
        // or any with just a path, like banana.png
        // check link is same host as $this->url and not a subdomain
        return parse_url( $link, PHP_URL_HOST ) === parse_url(
            $this->wp_site_url,
            PHP_URL_HOST
        );
    }

    public function removeQueryStringFromInternalLink( $element ) {
        $attribute_to_change = '';
        $url_to_change = '';

        if ( $element->hasAttribute( 'href' ) ) {
            $attribute_to_change = 'href';
        } elseif ( $element->hasAttribute( 'src' ) ) {
            $attribute_to_change = 'src';
            // skip elements without href or src
        } else {
            return;
        }

        $url_to_change = $element->getAttribute( $attribute_to_change );

        if ( $this->isInternalLink( $url_to_change ) ) {
            // strip anything from the ? onwards
            // https://stackoverflow.com/a/42476194/1668057
            $element->setAttribute(
                $attribute_to_change,
                strtok( $url_to_change, '?' )
            );
        }
    }


    public function detectEscapedSiteURLs() {
        // NOTE: this does return the expected http:\/\/172.18.0.3
        // but the PHP error log will escape again and
        // show http:\\/\\/172.18.0.3
        $escaped_site_url = addcslashes( get_option( 'siteurl' ), '/' );

        // if ( strpos( $this->raw_html, $escaped_site_url ) !== false ) {
        // TODO: renable this function being called. needs to be on
        // raw HTML, so ideally after the Processor has done all other
        // XML things, so no need to parse again
        // suggest adding a processRawHTML function, that
        // includes this stuff.. and call it within the getHTML function
        // or finalizeProcessing or such....
        // $this->rewriteEscapedURLs($wp_site_env,
        // $new_paths);
        // }
    }

    public function rewriteEscapedURLs() {
        /*
        This function will be a bit more costly. To cover bases like:

         data-images="[&quot;https:\/\/mysite.example.com\/wp...
        from the onepress(?) theme, for example

        */

        $rewritten_source = str_replace(
            array(
                addcslashes( $this->wp_site_env['wp_active_theme'], '/' ),
                addcslashes( $this->wp_site_env['wp_themes'], '/' ),
                addcslashes( $this->wp_site_env['wp_uploads'], '/' ),
                addcslashes( $this->wp_site_env['wp_plugins'], '/' ),
                addcslashes( $this->wp_site_env['wp_content'], '/' ),
                addcslashes( $this->wp_site_env['wp_inc'], '/' ),
            ),
            array(
                addcslashes( $this->new_paths['new_active_theme_path'], '/' ),
                addcslashes( $this->new_paths['new_themes_path'], '/' ),
                addcslashes( $this->new_paths['new_uploads_path'], '/' ),
                addcslashes( $this->new_paths['new_plugins_path'], '/' ),
                addcslashes( $this->new_paths['new_wp_content_path'], '/' ),
                addcslashes( $this->new_paths['new_wpinc_path'], '/' ),
            ),
            $this->response['body']
        );

        $this->setResponseBody( $rewritten_source );
    }

    public function rewriteWPPaths( $element ) {
        if ( ! $this->rewriteWPPaths ) {
            return;
        }

        $attribute_to_change = '';
        $url_to_change = '';

        if ( $element->hasAttribute( 'href' ) ) {
            $attribute_to_change = 'href';
        } elseif ( $element->hasAttribute( 'src' ) ) {
            $attribute_to_change = 'src';
            // skip elements without href or src
        } else {
            return;
        }

        $url_to_change = $element->getAttribute( $attribute_to_change );

        if ( $this->isInternalLink( $url_to_change ) ) {
            // rewrite URLs, starting with longest paths down to shortest
            // TODO: is the internal link check needed here or these
            // arr values are already normalized?
            $rewritten_url = str_replace(
                array(
                    $this->wp_site_env['wp_active_theme'],
                    $this->wp_site_env['wp_themes'],
                    $this->wp_site_env['wp_uploads'],
                    $this->wp_site_env['wp_plugins'],
                    $this->wp_site_env['wp_content'],
                    $this->wp_site_env['wp_inc'],
                ),
                array(
                    $this->new_paths['new_active_theme_path'],
                    $this->new_paths['new_themes_path'],
                    $this->new_paths['new_uploads_path'],
                    $this->new_paths['new_plugins_path'],
                    $this->new_paths['new_wp_content_path'],
                    $this->new_paths['new_wpinc_path'],
                ),
                $url_to_change
            );

            $element->setAttribute( $attribute_to_change, $rewritten_url );
        }
    }

    public function getHTML() {
        return $this->xml_doc->saveHtml();
    }

    // NOTE: separate from WP rewrites in case people have disabled that
    public function rewriteBaseURL( $element ) {
        if ( $element->hasAttribute( 'href' ) ) {
            $attribute_to_change = 'href';
        } elseif ( $element->hasAttribute( 'src' ) ) {
            $attribute_to_change = 'src';
        } else {
            return;
        }

        $url_to_change = $element->getAttribute( $attribute_to_change );

        // check it actually needs to be changed
        if ( $this->isInternalLink( $url_to_change ) ) {
            $rewritten_url = str_replace(
                // TODO: test this won't touch subdomains, shouldn't
                $this->wp_site_url,
                $this->baseUrl,
                $url_to_change
            );

            $element->setAttribute( $attribute_to_change, $rewritten_url );
        }
    }

    /*
    TODO
    public function setBaseHref() {
        // TODO: don't set for offline usage?
        if ( $this->useBaseHref ) {
            // TODO: create DOM node properly here
        } else {

        }

        // TODO: re-implement as separate func as another processing layer
        // error_log('SKIPPING absolute path rewriting');
        // if ( $this->useBaseHref ) {
        // $responseBody = str_replace(
        // '<head>',
        // "<head>\n<base href=\"" .
        // esc_attr( $new_URL ) . "/\" />\n",
        // $responseBody
        // );
        // } else {
        // $responseBody = str_replace(
        // '<head>',
        // "<head>\n<base href=\"/\" />\n",
        // $responseBody
        // );
        // }
    }
    */

    public function rewriteForOfflineUsage( $element ) {

        // elseif ( $allowOfflineUsage ) {
        // detect urls starting with our domain and append index.html to
        // the end if they end in /
        // TODO: re-implement as separate function as
        // another processing layer
        // error_log('SKIPPING offline usage rewriting');
        // foreach($xml->getElementsByTagName('a') as $link) {
        // $original_link = $link->getAttribute("href");
        //
        // process links from our site only
        // if (strpos($original_link, $oldDomain) !== false) {
        //
        // }
        //
        // $link->setAttribute('href', $original_link . 'index.html');
        // }
        //
        // }
        // TODO: should we check for incorrectly linked references?
        // like an http link on a WP site served from https? - probably not
    }
}

