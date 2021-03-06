<?php

// TODO: if this fails to locate the local file for the remote,
// it should fall back to regular crawl processing method
// (where response status will also be checked in case of 404)
class FileCopier {
    public function __construct( $url, $wp_site_url, $wp_site_path ) {
        $this->url = $url;
        $this->wp_site_url = $wp_site_url;
        $this->wp_site_path = $wp_site_path;
    }

    public function getLocalFileForURL() {
        /*
        Take the public URL and return the location on the filesystem

        ie http://domain.com/wp-content/somefile.jpg

        replace the WP site url with the WP site path

        ie

        replace http://domain.com/ with /var/www/domain.com/html/

        resulting in

        ie /var/www/domain.com/html/wp-content/somefile.jpg

        */
        return(
            str_replace( $this->wp_site_url, $this->wp_site_path, $this->url )
        );
    }

    public function copyFile( $archive_dir ) {
        $urlInfo = parse_url( $this->url );
        $pathInfo = array();

        $local_file = $this->getLocalFileForURL();

        // TODO: here we can allow certain external host files to be crawled
        if ( ! isset( $urlInfo['path'] ) ) {
            return false;
        }

        $pathInfo = pathinfo( $urlInfo['path'] );

        $directory_in_archive =
            isset( $pathInfo['dirname'] ) ?
            $pathInfo['dirname'] :
            '';

        if ( isset( $_POST['subdirectory'] ) ) {
            $directory_in_archive = str_replace(
                $_POST['subdirectory'],
                '',
                $directory_in_archive
            );
        }

        $fileDir = $archive_dir . ltrim( $directory_in_archive, '/' );

        if ( ! file_exists( $fileDir ) ) {
            wp_mkdir_p( $fileDir );
        }

        $fileExtension = $pathInfo['extension'];

        $fileName =
            $fileDir . '/' . $pathInfo['filename'] . '.' . $fileExtension;

        if ( is_file( $local_file ) ) {
            copy( $local_file, $fileName );
        } else {
            error_log( 'Fail trying to copy local file: ' . $local_file );
        }
    }
}
