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

if (!defined('GNUSOCIAL')) { exit(1); }

/**
 * Table Definition for file
 */
class File extends Managed_DataObject
{
    public $__table = 'file';                            // table name
    public $id;                              // int(4)  primary_key not_null
    public $urlhash;                         // varchar(64)  unique_key
    public $url;                             // text
    public $filehash;                        // varchar(64)     indexed
    public $mimetype;                        // varchar(50)
    public $size;                            // int(4)
    public $title;                           // varchar(191)   not 255 because utf8mb4 takes more space
    public $date;                            // int(4)
    public $protected;                       // int(4)
    public $filename;                        // varchar(191)   not 255 because utf8mb4 takes more space
    public $width;                           // int(4)
    public $height;                          // int(4)
    public $modified;                        // timestamp()   not_null default_CURRENT_TIMESTAMP

    const URLHASH_ALG = 'sha256';
    const FILEHASH_ALG = 'sha256';

    public static function schemaDef()
    {
        return array(
            'fields' => array(
                'id' => array('type' => 'serial', 'not null' => true),
                'urlhash' => array('type' => 'varchar', 'length' => 64, 'not null' => true, 'description' => 'sha256 of destination URL (url field)'),
                'url' => array('type' => 'text', 'description' => 'destination URL after following possible redirections'),
                'filehash' => array('type' => 'varchar', 'length' => 64, 'not null' => false, 'description' => 'sha256 of the file contents, only for locally stored files of course'),
                'mimetype' => array('type' => 'varchar', 'length' => 50, 'description' => 'mime type of resource'),
                'size' => array('type' => 'int', 'description' => 'size of resource when available'),
                'title' => array('type' => 'varchar', 'length' => 191, 'description' => 'title of resource when available'),
                'date' => array('type' => 'int', 'description' => 'date of resource according to http query'),
                'protected' => array('type' => 'int', 'description' => 'true when URL is private (needs login)'),
                'filename' => array('type' => 'varchar', 'length' => 191, 'description' => 'if a local file, name of the file'),
                'width' => array('type' => 'int', 'description' => 'width in pixels, if it can be described as such and data is available'),
                'height' => array('type' => 'int', 'description' => 'height in pixels, if it can be described as such and data is available'),

                'modified' => array('type' => 'timestamp', 'not null' => true, 'description' => 'date this record was modified'),
            ),
            'primary key' => array('id'),
            'unique keys' => array(
                'file_urlhash_key' => array('urlhash'),
            ),
            'indexes' => array(
                'file_filehash_idx' => array('filehash'),
            ),
        );
    }

    function isProtected($url) {
        return 'http://www.facebook.com/login.php' === $url;
    }

    /**
     * Save a new file record.
     *
     * @param array $redir_data lookup data eg from File_redirection::where()
     * @param string $given_url
     * @return File
     */
    public static function saveNew(array $redir_data, $given_url) {

        // I don't know why we have to keep doing this but I'm adding this last check to avoid
        // uniqueness bugs.

        $file = File::getKV('urlhash', self::hashurl($given_url));
        
        if (!$file instanceof File) {
            $file = new File;
            $file->urlhash = self::hashurl($given_url);
            $file->url = $given_url;
            if (!empty($redir_data['protected'])) $file->protected = $redir_data['protected'];
            if (!empty($redir_data['title'])) $file->title = $redir_data['title'];
            if (!empty($redir_data['type'])) $file->mimetype = $redir_data['type'];
            if (!empty($redir_data['size'])) $file->size = intval($redir_data['size']);
            if (isset($redir_data['time']) && $redir_data['time'] > 0) $file->date = intval($redir_data['time']);
            $file_id = $file->insert();
        }

        Event::handle('EndFileSaveNew', array($file, $redir_data, $given_url));
        assert ($file instanceof File);
        return $file;
    }

    /**
     * Go look at a URL and possibly save data about it if it's new:
     * - follow redirect chains and store them in file_redirection
     * - if a thumbnail is available, save it in file_thumbnail
     * - save file record with basic info
     * - optionally save a file_to_post record
     * - return the File object with the full reference
     *
     * @fixme refactor this mess, it's gotten pretty scary.
     * @param string $given_url the URL we're looking at
     * @param int $notice_id (optional)
     * @param bool $followRedirects defaults to true
     *
     * @return mixed File on success, -1 on some errors
     *
     * @throws ServerException on failure
     */
    public static function processNew($given_url, $notice_id=null, $followRedirects=true) {
        if (empty($given_url)) {
            throw new ServerException('No given URL to process');
        }

        $given_url = File_redirection::_canonUrl($given_url);
        if (empty($given_url)) {
            throw new ServerException('No canonical URL from given URL to process');
        }

        $file = null;

        try {
            $file = File::getByUrl($given_url);
        } catch (NoResultException $e) {
            // First check if we have a lookup trace for this URL already
            try {
                $file_redir = File_redirection::getByUrl($given_url);
                $file = File::getKV('id', $file_redir->file_id);
                if (!$file instanceof File) {
                    // File did not exist, let's clean up the File_redirection entry
                    $file_redir->delete();
                }
            } catch (NoResultException $e) {
                // We just wanted to doublecheck whether a File_thumbnail we might've had
                // actually referenced an existing File object.
            }
        }

        // If we still don't have a File object, let's create one now!
        if (!$file instanceof File) {
            // @fixme for new URLs this also looks up non-redirect data
            // such as target content type, size, etc, which we need
            // for File::saveNew(); so we call it even if not following
            // new redirects.
            $redir_data = File_redirection::where($given_url);
            if (is_array($redir_data)) {
                $redir_url = $redir_data['url'];
            } elseif (is_string($redir_data)) {
                $redir_url = $redir_data;
                $redir_data = array();
            } else {
                // TRANS: Server exception thrown when a URL cannot be processed.
                throw new ServerException(sprintf(_("Cannot process URL '%s'"), $given_url));
            }

            if ($redir_url === $given_url || !$followRedirects) {
                // Save the File object based on our lookup trace
                $file = File::saveNew($redir_data, $given_url);
            } else {
                // This seems kind of messed up... for now skipping this part
                // if we're already under a redirect, so we don't go into
                // horrible infinite loops if we've been given an unstable
                // redirect (where the final destination of the first request
                // doesn't match what we get when we ask for it again).
                //
                // Seen in the wild with clojure.org, which redirects through
                // wikispaces for auth and appends session data in the URL params.
                $file = self::processNew($redir_url, $notice_id, /*followRedirects*/false);
                File_redirection::saveNew($redir_data, $file->id, $given_url);
            }

            if (!$file instanceof File) {
                // This should only happen if File::saveNew somehow did not return a File object,
                // though we have an assert for that in case the event there might've gone wrong.
                // If anything else goes wrong, there should've been an exception thrown.
                throw new ServerException('URL processing failed without new File object');
            }
        }

        if (!empty($notice_id)) {
            File_to_post::processNew($file->id, $notice_id);
        }
        return $file;
    }

    public static function respectsQuota(Profile $scoped, $fileSize) {
        if ($fileSize > common_config('attachments', 'file_quota')) {
            // TRANS: Message used to be inserted as %2$s in  the text "No file may
            // TRANS: be larger than %1$d byte and the file you sent was %2$s.".
            // TRANS: %1$d is the number of bytes of an uploaded file.
            $fileSizeText = sprintf(_m('%1$d byte','%1$d bytes',$fileSize),$fileSize);

            $fileQuota = common_config('attachments', 'file_quota');
            // TRANS: Message given if an upload is larger than the configured maximum.
            // TRANS: %1$d (used for plural) is the byte limit for uploads,
            // TRANS: %2$s is the proper form of "n bytes". This is the only ways to have
            // TRANS: gettext support multiple plurals in the same message, unfortunately...
            throw new ClientException(
                    sprintf(_m('No file may be larger than %1$d byte and the file you sent was %2$s. Try to upload a smaller version.',
                              'No file may be larger than %1$d bytes and the file you sent was %2$s. Try to upload a smaller version.',
                              $fileQuota),
                    $fileQuota, $fileSizeText));
        }

        $file = new File;

        $query = "select sum(size) as total from file join file_to_post on file_to_post.file_id = file.id join notice on file_to_post.post_id = notice.id where profile_id = {$scoped->id} and file.url like '%/notice/%/file'";
        $file->query($query);
        $file->fetch();
        $total = $file->total + $fileSize;
        if ($total > common_config('attachments', 'user_quota')) {
            // TRANS: Message given if an upload would exceed user quota.
            // TRANS: %d (number) is the user quota in bytes and is used for plural.
            throw new ClientException(
                    sprintf(_m('A file this large would exceed your user quota of %d byte.',
                              'A file this large would exceed your user quota of %d bytes.',
                              common_config('attachments', 'user_quota')),
                    common_config('attachments', 'user_quota')));
        }
        $query .= ' AND EXTRACT(month FROM file.modified) = EXTRACT(month FROM now()) and EXTRACT(year FROM file.modified) = EXTRACT(year FROM now())';
        $file->query($query);
        $file->fetch();
        $total = $file->total + $fileSize;
        if ($total > common_config('attachments', 'monthly_quota')) {
            // TRANS: Message given id an upload would exceed a user's monthly quota.
            // TRANS: $d (number) is the monthly user quota in bytes and is used for plural.
            throw new ClientException(
                    sprintf(_m('A file this large would exceed your monthly quota of %d byte.',
                              'A file this large would exceed your monthly quota of %d bytes.',
                              common_config('attachments', 'monthly_quota')),
                    common_config('attachments', 'monthly_quota')));
        }
        return true;
    }

    // where should the file go?

    static function filename(Profile $profile, $origname, $mimetype)
    {
        $ext = self::guessMimeExtension($mimetype);

        // Normalize and make the original filename more URL friendly.
        $origname = basename($origname, ".$ext");
        if (class_exists('Normalizer')) {
            // http://php.net/manual/en/class.normalizer.php
            // http://www.unicode.org/reports/tr15/
            $origname = Normalizer::normalize($origname, Normalizer::FORM_KC);
        }
        $origname = preg_replace('/[^A-Za-z0-9\.\_]/', '_', $origname);

        $nickname = $profile->getNickname();
        $datestamp = strftime('%Y%m%d', time());
        do {
            // generate new random strings until we don't run into a filename collision.
            $random = strtolower(common_confirmation_code(16));
            $filename = "$nickname-$datestamp-$origname-$random.$ext";
        } while (file_exists(self::path($filename)));
        return $filename;
    }

    static function guessMimeExtension($mimetype)
    {
        try {
            $ext = common_supported_mime_to_ext($mimetype);
        } catch (Exception $e) {
            // We don't support this mimetype, but let's guess the extension
            $ext = substr(strrchr($mimetype, '/'), 1);
        }
        return strtolower($ext);
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
            // TRANS: Client exception thrown if a file upload does not have a valid name.
            throw new ClientException(_("Invalid filename."));
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
            // TRANS: Client exception thrown if a file upload does not have a valid name.
            throw new ClientException(_("Invalid filename."));
        }

        if (common_config('site','private')) {

            return common_local_url('getfile',
                                array('filename' => $filename));

        }

        if (GNUsocial::useHTTPS()) {

            $sslserver = common_config('attachments', 'sslserver');

            if (empty($sslserver)) {
                // XXX: this assumes that background dir == site dir + /file/
                // not true if there's another server
                if (is_string(common_config('site', 'sslserver')) &&
                    mb_strlen(common_config('site', 'sslserver')) > 0) {
                    $server = common_config('site', 'sslserver');
                } else if (common_config('site', 'server')) {
                    $server = common_config('site', 'server');
                }
                $path = common_config('site', 'path') . '/file/';
            } else {
                $server = $sslserver;
                $path   = common_config('attachments', 'sslpath');
                if (empty($path)) {
                    $path = common_config('attachments', 'path');
                }
            }

            $protocol = 'https';
        } else {
            $path = common_config('attachments', 'path');
            $server = common_config('attachments', 'server');

            if (empty($server)) {
                $server = common_config('site', 'server');
            }

            $ssl = common_config('attachments', 'ssl');

            $protocol = ($ssl) ? 'https' : 'http';
        }

        if ($path[strlen($path)-1] != '/') {
            $path .= '/';
        }

        if ($path[0] != '/') {
            $path = '/'.$path;
        }

        return $protocol.'://'.$server.$path.$filename;
    }

    function getEnclosure(){
        $enclosure = (object) array();
        foreach (array('title', 'url', 'date', 'modified', 'size', 'mimetype') as $key) {
            $enclosure->$key = $this->$key;
        }

        $needMoreMetadataMimetypes = array(null, 'application/xhtml+xml');

        if (!isset($this->filename) && in_array(common_bare_mime($enclosure->mimetype), $needMoreMetadataMimetypes)) {
            // This fetches enclosure metadata for non-local links with unset/HTML mimetypes,
            // which may be enriched through oEmbed or similar (implemented as plugins)
            Event::handle('FileEnclosureMetadata', array($this, &$enclosure));
        }
        if (empty($enclosure->mimetype) || in_array(common_bare_mime($enclosure->mimetype), $needMoreMetadataMimetypes)) {
            // This means we either don't know what it is, so it can't
            // be shown as an enclosure, or it is an HTML link which
            // does not link to a resource with further metadata.
            throw new ServerException('Unknown enclosure mimetype, not enough metadata');
        }
        return $enclosure;
    }

    /**
     * Get the attachment's thumbnail record, if any.
     * Make sure you supply proper 'int' typed variables (or null).
     *
     * @param $width  int   Max width of thumbnail in pixels. (if null, use common_config values)
     * @param $height int   Max height of thumbnail in pixels. (if null, square-crop to $width)
     * @param $crop   bool  Crop to the max-values' aspect ratio
     *
     * @return File_thumbnail
     *
     * @throws UseFileAsThumbnailException  if the file is considered an image itself and should be itself as thumbnail
     * @throws UnsupportedMediaException    if, despite trying, we can't understand how to make a thumbnail for this format
     * @throws ServerException              on various other errors
     */
    public function getThumbnail($width=null, $height=null, $crop=false, $force_still=true)
    {
        // Get some more information about this file through our ImageFile class
        $image = ImageFile::fromFileObject($this);
        if ($image->animated && !common_config('thumbnail', 'animated')) {
            // null  means "always use file as thumbnail"
            // false means you get choice between frozen frame or original when calling getThumbnail
            if (is_null(common_config('thumbnail', 'animated')) || !$force_still) {
                throw new UseFileAsThumbnailException($this->id);
            }
        }

        return $image->getFileThumbnail($width, $height, $crop);
    }

    public function getPath()
    {
        $filepath = self::path($this->filename);
        if (!file_exists($filepath)) {
            throw new FileNotFoundException($filepath);
        }
        return $filepath;
    }

    public function getUrl()
    {
        if (!empty($this->filename)) {
            // A locally stored file, so let's generate a URL for our instance.
            $url = self::url($this->filename);
            if (self::hashurl($url) !== $this->urlhash) {
                // For indexing purposes, in case we do a lookup on the 'url' field.
                // also we're fixing possible changes from http to https, or paths
                $this->updateUrl($url);
            }
            return $url;
        }

        // No local filename available, return the URL we have stored
        return $this->url;
    }

    static public function getByUrl($url)
    {
        $file = new File();
        $file->urlhash = self::hashurl($url);
        if (!$file->find(true)) {
            throw new NoResultException($file);
        }
        return $file;
    }

    /**
     * @param   string  $hashstr    String of (preferrably lower case) hexadecimal characters, same as result of 'hash_file(...)'
     */
    static public function getByHash($hashstr, $alg=File::FILEHASH_ALG)
    {
        $file = new File();
        $file->filehash = strtolower($hashstr);
        if (!$file->find(true)) {
            throw new NoResultException($file);
        }
        return $file;
    }

    public function updateUrl($url)
    {
        $file = File::getKV('urlhash', self::hashurl($url));
        if ($file instanceof File) {
            throw new ServerException('URL already exists in DB');
        }
        $sql = 'UPDATE %1$s SET urlhash=%2$s, url=%3$s WHERE urlhash=%4$s;';
        $result = $this->query(sprintf($sql, $this->__table,
                                             $this->_quote((string)self::hashurl($url)),
                                             $this->_quote((string)$url),
                                             $this->_quote((string)$this->urlhash)));
        if ($result === false) {
            common_log_db_error($this, 'UPDATE', __FILE__);
            throw new ServerException("Could not UPDATE {$this->__table}.url");
        }

        return $result;
    }

    /**
     * Blow the cache of notices that link to this URL
     *
     * @param boolean $last Whether to blow the "last" cache too
     *
     * @return void
     */

    function blowCache($last=false)
    {
        self::blow('file:notice-ids:%s', $this->urlhash);
        if ($last) {
            self::blow('file:notice-ids:%s;last', $this->urlhash);
        }
        self::blow('file:notice-count:%d', $this->id);
    }

    /**
     * Stream of notices linking to this URL
     *
     * @param integer $offset   Offset to show; default is 0
     * @param integer $limit    Limit of notices to show
     * @param integer $since_id Since this notice
     * @param integer $max_id   Before this notice
     *
     * @return array ids of notices that link to this file
     */

    function stream($offset=0, $limit=NOTICES_PER_PAGE, $since_id=0, $max_id=0)
    {
        $stream = new FileNoticeStream($this);
        return $stream->getNotices($offset, $limit, $since_id, $max_id);
    }

    function noticeCount()
    {
        $cacheKey = sprintf('file:notice-count:%d', $this->id);
        
        $count = self::cacheGet($cacheKey);

        if ($count === false) {

            $f2p = new File_to_post();

            $f2p->file_id = $this->id;

            $count = $f2p->count();

            self::cacheSet($cacheKey, $count);
        } 

        return $count;
    }

    public function isLocal()
    {
        return !empty($this->filename);
    }

    public function delete($useWhere=false)
    {
        // Delete the file, if it exists locally
        if (!empty($this->filename) && file_exists(self::path($this->filename))) {
            $deleted = @unlink(self::path($this->filename));
            if (!$deleted) {
                common_log(LOG_ERR, sprintf('Could not unlink existing file: "%s"', self::path($this->filename)));
            }
        }

        // Clear out related things in the database and filesystem, such as thumbnails
        if (Event::handle('FileDeleteRelated', array($this))) {
            $thumbs = new File_thumbnail();
            $thumbs->file_id = $this->id;
            if ($thumbs->find()) {
                while ($thumbs->fetch()) {
                    $thumbs->delete();
                }
            }
        }

        // And finally remove the entry from the database
        return parent::delete($useWhere);
    }

    public function getTitle()
    {
        $title = $this->title ?: $this->filename;

        return $title ?: null;
    }

    static public function hashurl($url)
    {
        if (empty($url)) {
            throw new Exception('No URL provided to hash algorithm.');
        }
        return hash(self::URLHASH_ALG, $url);
    }

    static public function beforeSchemaUpdate()
    {
        $table = strtolower(get_called_class());
        $schema = Schema::get();
        $schemadef = $schema->getTableDef($table);

        // 2015-02-19 We have to upgrade our table definitions to have the urlhash field populated
        if (isset($schemadef['fields']['urlhash']) && isset($schemadef['unique keys']['file_urlhash_key'])) {
            // We already have the urlhash field, so no need to migrate it.
            return;
        }
        echo "\nFound old $table table, upgrading it to contain 'urlhash' field...";
        // We have to create a urlhash that is _not_ the primary key,
        // transfer data and THEN run checkSchema
        $schemadef['fields']['urlhash'] = array (
                                              'type' => 'varchar',
                                              'length' => 64,
                                              'not null' => true,
                                              'description' => 'sha256 of destination URL (url field)',
                                            );
        $schemadef['fields']['url'] = array (
                                              'type' => 'text',
                                              'description' => 'destination URL after following possible redirections',
                                            );
        unset($schemadef['unique keys']);
        $schema->ensureTable($table, $schemadef);
        echo "DONE.\n";

        $classname = ucfirst($table);
        $tablefix = new $classname;
        // urlhash is hash('sha256', $url) in the File table
        echo "Updating urlhash fields in $table table...";
        // Maybe very MySQL specific :(
        $tablefix->query(sprintf('UPDATE %1$s SET %2$s=%3$s;',
                            $schema->quoteIdentifier($table),
                            'urlhash',
                            // The line below is "result of sha256 on column `url`"
                            'SHA2(url, 256)'));
        echo "DONE.\n";
        echo "Resuming core schema upgrade...";
    }
}
