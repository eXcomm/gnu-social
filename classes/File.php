<?php
/*
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2008, 2009, StatusNet, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.     See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.     If not, see <http://www.gnu.org/licenses/>.
 */

if (!defined('STATUSNET') && !defined('LACONICA')) { exit(1); }

require_once INSTALLDIR.'/classes/Memcached_DataObject.php';
require_once INSTALLDIR.'/classes/File_redirection.php';
require_once INSTALLDIR.'/classes/File_oembed.php';
require_once INSTALLDIR.'/classes/File_thumbnail.php';
require_once INSTALLDIR.'/classes/File_to_post.php';
//require_once INSTALLDIR.'/classes/File_redirection.php';

/**
 * Table Definition for file
 */

class File extends Memcached_DataObject
{
    ###START_AUTOCODE
    /* the code below is auto generated do not remove the above tag */

    public $__table = 'file';                            // table name
    public $id;                              // int(4)  primary_key not_null
    public $url;                             // varchar(255)  unique_key
    public $mimetype;                        // varchar(50)
    public $size;                            // int(4)
    public $title;                           // varchar(255)
    public $date;                            // int(4)
    public $protected;                       // int(4)
    public $filename;                        // varchar(255)
    public $modified;                        // timestamp()   not_null default_CURRENT_TIMESTAMP

    /* Static get */
    function staticGet($k,$v=NULL) { return Memcached_DataObject::staticGet('File',$k,$v); }

    /* the code above is auto generated do not remove the tag below */
    ###END_AUTOCODE

    function isProtected($url) {
        return 'http://www.facebook.com/login.php' === $url;
    }

    function getAttachments($post_id) {
        $query = "select file.* from file join file_to_post on (file_id = file.id) join notice on (post_id = notice.id) where post_id = " . $this->escape($post_id);
        $this->query($query);
        $att = array();
        while ($this->fetch()) {
            $att[] = clone($this);
        }
        $this->free();
        return $att;
    }

    /**
     * Save a new file record.
     *
     * @param array $redir_data lookup data eg from File_redirection::where()
     * @param string $given_url
     * @return File
     */
    function saveNew(array $redir_data, $given_url) {
        $x = new File;
        $x->url = $given_url;
        if (!empty($redir_data['protected'])) $x->protected = $redir_data['protected'];
        if (!empty($redir_data['title'])) $x->title = $redir_data['title'];
        if (!empty($redir_data['type'])) $x->mimetype = $redir_data['type'];
        if (!empty($redir_data['size'])) $x->size = intval($redir_data['size']);
        if (isset($redir_data['time']) && $redir_data['time'] > 0) $x->date = intval($redir_data['time']);
        $file_id = $x->insert();

        if (isset($redir_data['type'])
            && (('text/html' === substr($redir_data['type'], 0, 9) || 'application/xhtml+xml' === substr($redir_data['type'], 0, 21)))
            && ($oembed_data = File_oembed::_getOembed($given_url))) {

            $fo = File_oembed::staticGet('file_id', $file_id);

            if (empty($fo)) {
                File_oembed::saveNew($oembed_data, $file_id);
            } else {
                common_log(LOG_WARNING, "Strangely, a File_oembed object exists for new file $file_id", __FILE__);
            }
        }
        return $x;
    }

    function processNew($given_url, $notice_id=null) {
        if (empty($given_url)) return -1;   // error, no url to process
        $given_url = File_redirection::_canonUrl($given_url);
        if (empty($given_url)) return -1;   // error, no url to process
        $file = File::staticGet('url', $given_url);
        if (empty($file)) {
            $file_redir = File_redirection::staticGet('url', $given_url);
            if (empty($file_redir)) {
                $redir_data = File_redirection::where($given_url);
                if (is_array($redir_data)) {
                    $redir_url = $redir_data['url'];
                } elseif (is_string($redir_data)) {
                    $redir_url = $redir_data;
                } else {
                    throw new ServerException("Can't process url '$given_url'");
                }
                // TODO: max field length
                if ($redir_url === $given_url || strlen($redir_url) > 255) {
                    $x = File::saveNew($redir_data, $given_url);
                    $file_id = $x->id;
                } else {
                    $x = File::processNew($redir_url, $notice_id);
                    $file_id = $x->id;
                    File_redirection::saveNew($redir_data, $file_id, $given_url);
                }
            } else {
                $file_id = $file_redir->file_id;
            }
        } else {
            $file_id = $file->id;
            $x = $file;
        }

        if (empty($x)) {
            $x = File::staticGet($file_id);
            if (empty($x)) {
                throw new ServerException("Robin thinks something is impossible.");
            }
        }

        if (!empty($notice_id)) {
            File_to_post::processNew($file_id, $notice_id);
        }
        return $x;
    }

    function isRespectsQuota($user,$fileSize) {

        if ($fileSize > common_config('attachments', 'file_quota')) {
            return sprintf(_('No file may be larger than %d bytes ' .
                             'and the file you sent was %d bytes. Try to upload a smaller version.'),
                           common_config('attachments', 'file_quota'), $fileSize);
        }

        $query = "select sum(size) as total from file join file_to_post on file_to_post.file_id = file.id join notice on file_to_post.post_id = notice.id where profile_id = {$user->id} and file.url like '%/notice/%/file'";
        $this->query($query);
        $this->fetch();
        $total = $this->total + $fileSize;
        if ($total > common_config('attachments', 'user_quota')) {
            return sprintf(_('A file this large would exceed your user quota of %d bytes.'), common_config('attachments', 'user_quota'));
        }
        $query .= ' AND EXTRACT(month FROM file.modified) = EXTRACT(month FROM now()) and EXTRACT(year FROM file.modified) = EXTRACT(year FROM now())';
        $this->query($query);
        $this->fetch();
        $total = $this->total + $fileSize;
        if ($total > common_config('attachments', 'monthly_quota')) {
            return sprintf(_('A file this large would exceed your monthly quota of %d bytes.'), common_config('attachments', 'monthly_quota'));
        }
        return true;
    }

    // where should the file go?

    static function filename($profile, $basename, $mimetype)
    {
        require_once 'MIME/Type/Extension.php';
        $mte = new MIME_Type_Extension();
        $ext = $mte->getExtension($mimetype);
        $nickname = $profile->nickname;
        $datestamp = strftime('%Y%m%dT%H%M%S', time());
        $random = strtolower(common_confirmation_code(32));
        return "$nickname-$datestamp-$random.$ext";
    }

    /**
     * Validation for as-saved base filenames
     */
    static function validFilename($filename)
    {
        return preg_match('/^[A-Za-z0-9._-]+$/', $filename);
    }

    /**
     * @throws ClientException on invalid filename
     */
    static function path($filename)
    {
        if (!self::validFilename($filename)) {
            throw new ClientException("Invalid filename");
        }
        $dir = common_config('attachments', 'dir');

        if ($dir[strlen($dir)-1] != '/') {
            $dir .= '/';
        }

        return $dir . $filename;
    }

    static function url($filename)
    {
        if (!self::validFilename($filename)) {
            throw new ClientException("Invalid filename");
        }
        if(common_config('site','private')) {

            return common_local_url('getfile',
                                array('filename' => $filename));

        } else {
            $path = common_config('attachments', 'path');

            if ($path[strlen($path)-1] != '/') {
                $path .= '/';
            }

            if ($path[0] != '/') {
                $path = '/'.$path;
            }

            $server = common_config('attachments', 'server');

            if (empty($server)) {
                $server = common_config('site', 'server');
            }

            $ssl = common_config('attachments', 'ssl');

            if (is_null($ssl)) { // null -> guess
                if (common_config('site', 'ssl') == 'always' &&
                    !common_config('attachments', 'server')) {
                    $ssl = true;
                } else {
                    $ssl = false;
                }
            }

            $protocol = ($ssl) ? 'https' : 'http';

            return $protocol.'://'.$server.$path.$filename;
        }
    }

    function getEnclosure(){
        $enclosure = (object) array();
        $enclosure->title=$this->title;
        $enclosure->url=$this->url;
        $enclosure->title=$this->title;
        $enclosure->date=$this->date;
        $enclosure->modified=$this->modified;
        $enclosure->size=$this->size;
        $enclosure->mimetype=$this->mimetype;

        if(! isset($this->filename)){
            $notEnclosureMimeTypes = array('text/html','application/xhtml+xml');
            $mimetype = strtolower($this->mimetype);
            $semicolon = strpos($mimetype,';');
            if($semicolon){
                $mimetype = substr($mimetype,0,$semicolon);
            }
            if(in_array($mimetype,$notEnclosureMimeTypes)){
                $oembed = File_oembed::staticGet('file_id',$this->id);
                if($oembed){
                    $mimetype = strtolower($oembed->mimetype);
                    $semicolon = strpos($mimetype,';');
                    if($semicolon){
                        $mimetype = substr($mimetype,0,$semicolon);
                    }
                    if(in_array($mimetype,$notEnclosureMimeTypes)){
                        return false;
                    }else{
                        if($oembed->mimetype) $enclosure->mimetype=$oembed->mimetype;
                        if($oembed->url) $enclosure->url=$oembed->url;
                        if($oembed->title) $enclosure->title=$oembed->title;
                        if($oembed->modified) $enclosure->modified=$oembed->modified;
                        unset($oembed->size);
                    }
                } else {
                    return false;
                }
            }
        }
        return $enclosure;
    }

    // quick back-compat hack, since there's still code using this
    function isEnclosure()
    {
        $enclosure = $this->getEnclosure();
        return !empty($enclosure);
    }
}

