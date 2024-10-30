<?php

// no direct access
defined('ABSPATH') or die('Restricted Access');

class chimpxpressJG_Cache {

    private $dir;
    private $useFTP;
    private $handler;

    public function __construct($dir, $useFTP = false, $handler = null) {
        global $wp_filesystem;

        $this->dir = $dir;
        $this->useFTP = $useFTP;
        $this->handler = $handler ?? $wp_filesystem;

        $this->prepareDir();
    }

    private function prepareDir() {
        if (!$this->handler) {
            return;
        }

        // create directory
        if (!$this->handler->is_dir($this->dir)) {
            if ($this->useFTP) {
                ftp_mkdir($this->handler, $this->dir);
            } else {
                $this->handler->mkdir($this->dir);
            }
        }

        // disallow access to cache files
        if (!$this->handler->is_file($this->dir . DS . '.htaccess')) {
            if ($this->useFTP) {
                global $wp_filesystem;
                $temp = tmpfile();
                $wp_filesystem->put_contents($temp, "# disallow access to any files\nOrder Allow,Deny\nDeny from all");
                rewind($temp);
                ftp_fput($this->handler, $this->dir . DS . '.htaccess', $temp, FTP_ASCII);
            } else {
                $this->handler->put_contents($this->dir . DS . '.htaccess', "# disallow access to any files\nOrder Allow,Deny\nDeny from all");
            }
        }
    }
    private function getPathName($key) {
        return sprintf("%s/%s", $this->dir, sha1($key));
    }

    public function get($key, $expiration = 0) {
        if (!$this->handler || !$this->handler->is_dir($this->dir)) {
            return false;
        }

        $cachePath = $this->getPathName($key);

        if (!$this->handler->is_file($cachePath)) {
            return false;
        }

        if ($expiration > 0 && $this->handler->mtime($cachePath) < (time() - $expiration)) {
            $this->clear($key);
            return false;
        }

        if (!$this->handler->is_readable($cachePath)) {
            return false;
        }

        $cache = null;
        if ($this->handler->size($cachePath) > 0) {
            $cache = unserialize($this->handler->get_contents($cachePath));
        }

        return $cache;
    }

    public function set($key, $data) {
        if (!$this->handler->is_dir($this->dir)) {
            return false;
        }

        $cachePath = $this->getPathName($key);

        if ($this->useFTP) {
            global $wp_filesystem;

            $temp = tmpfile();
            $wp_filesystem->put_contents($temp, serialize($data));
            rewind($temp);
            if (!ftp_fput($this->handler, $cachePath, $temp, FTP_ASCII)) {
                return false;
            }
        } else {

            if (!$this->handler->is_writable($this->dir)) {
                return false;
            }

            if (!$this->handler->put_contents($cachePath, serialize($data))) {
                return false;
            }
        }

        return true;
    }

    public function clear($key) {
        $cachePath = $this->getPathName($key);

        if ($this->handler->is_file($cachePath)) {
            if ($this->useFTP) {
                ftp_delete($this->handler, $cachePath);
            } else {
                $this->handler->delete($cachePath);
            }
        }
    }
}
