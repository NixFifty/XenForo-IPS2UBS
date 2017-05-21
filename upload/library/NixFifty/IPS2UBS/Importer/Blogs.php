<?php

class NixFifty_IPS2UBS_Importer_Blogs extends XenForo_Importer_Abstract
{
    /**
     * Source database connection.
     *
     * @var Zend_Db_Adapter_Abstract
     */
    protected $_sourceDb;

    protected $_prefix;

    protected $_charset = 'windows-1252';

    protected $_config;

    protected $_userIdMap;

    protected $_retainKeys = false;

    protected $_categoryId;

    protected $_defaultTables = array(
        'ipb_blog_categories',
        'ipb_blog_blogs',
        'ipb_blog_entries'
    );

    public static function getName()
    {
        return 'UBS: Import from IPS Blogs (NixFifty)';
    }

    public function configure(XenForo_ControllerAdmin_Abstract $controller, array &$config)
    {
        if ($config)
        {
            $errors = $this->validateConfiguration($config);
            if ($errors)
            {
                return $controller->responseError($errors);
            }

            return true;
        }
        else
        {
            $config = XenForo_Application::getConfig();
            $dbConfig = $config->get('db');

            $viewParams = array(
                'config' => array(
                    'db' => array(
                        'host' => $dbConfig->host,
                        'port' => $dbConfig->port,
                        'username' => $dbConfig->username,
                        'password' => $dbConfig->password,
                        'dbname' => $dbConfig->dbname
                    )
                ),
                'addOnName' => str_replace('UBS: Import From ', '', self::getName())
            );
        }

        return $controller->responseView('NixFifty_IPS2UBS_ViewAdmin_Import_Config', 'nf_ips2ubs_import_config', $viewParams);
    }

    public function validateConfiguration(array &$config)
    {
        $errors = array();

	    $config['db']['prefix'] = preg_replace('/[^a-z0-9_]/i', '', $config['db']['prefix']);

	    if (empty($config['importLog']))
	    {
		    $errors[] = new XenForo_Phrase('nf_ips2ubs_no_import_log_table_specified');
	    }

        try
        {
            $db = Zend_Db::factory('mysqli',
                array(
                    'host' => $config['db']['host'],
                    'port' => $config['db']['port'],
                    'username' => $config['db']['username'],
                    'password' => $config['db']['password'],
                    'dbname' => $config['db']['dbname'],
                    'charset' => $config['db']['charset']
                )
            );
            $db->getConnection();
        }
        catch (Zend_Db_Exception $e)
        {
            $errors[] = new XenForo_Phrase('source_database_connection_details_not_correct_x', array('error' => $e->getMessage()));
        }

        if ($errors)
        {
            return $errors;
        }

	    try
	    {
		    $db->query('
				SELECT member_id
				FROM ' . $config['db']['prefix'] . 'members
				LIMIT 1
			');
	    }
	    catch (Zend_Db_Exception $e)
	    {
		    if ($config['db']['dbname'] === '')
		    {
			    $errors[] = new XenForo_Phrase('please_enter_database_name');
		    }
		    else
		    {
			    $errors[] = new XenForo_Phrase('table_prefix_or_database_name_is_not_correct');
		    }
	    }

	    if (!$errors)
	    {
		    $defaultCharset = $db->fetchOne("
				SELECT IF(conf_value = '' OR conf_value IS NULL, conf_default, conf_value)
				FROM {$config['db']['prefix']}core_sys_conf_settings
				WHERE conf_key = 'gb_char_set'
			");
		    if (!$defaultCharset || str_replace('-', '', strtolower($defaultCharset)) == 'iso88591')
		    {
			    $config['charset'] = 'windows-1252';
		    }
		    else
		    {
			    $config['charset'] = strtolower($defaultCharset);
		    }
	    }

        foreach ($this->_defaultTables AS $table)
        {
            $exists = $db->fetchOne("SHOW TABLES LIKE '$table'");

            if (!$exists)
            {
                $errors[] = new XenForo_Phrase('nf_ips2ubs_table_x_does_not_exist', array('tablename' => $table));
            }
        }

        return $errors;
    }

    protected function _bootstrap(array $config)
    {
        if ($this->_sourceDb)
        {
            // already run
            return;
        }

        @set_time_limit(0);

        $this->_config = $config;

        $this->_sourceDb = Zend_Db::factory('mysqli',
            array(
                'host' => $config['db']['host'],
                'port' => $config['db']['port'],
                'username' => $config['db']['username'],
                'password' => $config['db']['password'],
                'dbname' => $config['db']['dbname'],
                'charset' => $config['db']['charset']
            )
        );

	    if (empty($config['db']['charset']))
	    {
		    $this->_sourceDb->query('SET character_set_results = NULL');
	    }

	    $this->_prefix = preg_replace('/[^a-z0-9_]/i', '', $config['db']['prefix']);

	    if (!empty($config['charset']))
	    {
		    $this->_charset = $config['charset'];
	    }

	    define('IMPORT_LOG_TABLE', $this->_config['importLog']);
    }

    public function getSteps()
    {
        return array(
            'categories' => array(
                'title' => new XenForo_Phrase('nflj_ubs_import_categories')
            ),
            'blogs' => array(
                'title' => new XenForo_Phrase('nflj_ubs_import_blogs'),
                'depends' => array('categories')
            ),
            'blogEntries' => array(
                'title' => new XenForo_Phrase('nflj_ubs_import_blog_entries'),
                'depends' => array('blogs')
            ),
            'comments' => array(
                'title' => new XenForo_Phrase('nflj_ubs_import_comments'),
                'depends' => array('blogEntries')
            )
        );
    }

    public function stepCategories($start, array $options)
    {
        $options = array_merge(array(
            'limit' => 9999,
            'max' => false
        ), $options);

        $sDb = $this->_sourceDb;

        if ($options['max'] === false)
        {
            $options['max'] = $sDb->fetchOne('
				SELECT MAX(category_id)
				FROM ipb_blog_categories
			');
        }

        $categories = $sDb->fetchAll(
            $sDb->limit('
				SELECT *
				FROM ipb_blog_categories
				WHERE category_id > ?
				ORDER BY category_id
			', $options['limit'])
            , $start);
        if (!$categories)
        {
            return true;
        }

        XenForo_Db::beginTransaction();

        $next = 0;
        $total = 0;

        foreach ($categories AS $category)
        {
            $next = $category['category_id'];

            $imported = $this->_importCategory($category, $options);
            if ($imported)
            {
                $total++;
            }
        }

        XenForo_Db::commit();

        $this->_session->incrementStepImportTotal($total);

        return array($next, $options, $this->_getProgressOutput($next, $options['max']));
    }

    protected function _importCategory(array $category, array $options)
    {
        $model = $this->_getUBSImportersModel();

        $ubsCategory = array(
            'category_name' => $this->_convertToUtf8($category['category_title'], true),
            'display_order' => $category['category_position'],
            'category_options' => 'a:0:{}',
            'allowed_user_group_ids' => '-1',
        );

        $importedCategoryId = $model->importCategory($category['category_id'], $ubsCategory);

        return $importedCategoryId;
    }

    public function stepBlogs($start, array $options)
    {
        $options = array_merge(array(
            'limit' => 5,
            'max' => false
        ), $options);

	    $sDb = $this->_sourceDb;
	    $prefix = $this->_prefix;

	    if ($options['max'] === false)
	    {
		    $options['max'] = $sDb->fetchOne('
				SELECT MAX(blog_id)
				FROM ' . $prefix . 'blog_blogs
			');
	    }

        $blogs = $this->_getBlogs($start, $options);

        if (!$blogs)
        {
            return true;
        }

        $next = 0;
        $total = 0;

	    $this->_userIdMap = $this->_importModel->getUserIdsMapFromArray($blogs, 'member_id');

        foreach ($blogs AS $blog)
        {
            $next = $blog['blog_id'];
	        if (!isset($this->_userIdMap[$blog['member_id']]))
	        {
		        continue;
	        }

            $success = $this->_importBlog($blog, $options);
            if ($success)
            {
                $total++;
            }
        }

        $this->_session->incrementStepImportTotal($total);

        return array($next, $options, $this->_getProgressOutput($next, $options['max']));
    }

	protected function _getBlogs($start, array $options)
	{
		$sDb = $this->_sourceDb;
		$prefix = $this->_prefix;

		return $sDb->fetchAll(
			$sDb->limit('
				SELECT blog.*, member.name
				FROM ' . $prefix . 'blog_blogs AS blog
				INNER JOIN ' . $prefix . 'members AS member ON
					(blog.member_id = member.member_id)
				WHERE blog.blog_id > ?
				AND blog.blog_type = "local"
				ORDER BY blog.blog_id ASC
			', $options['limit'])
        , $start);
	}

    protected function _importBlog(array $blog, array $options)
    {
        $userId = $this->_userIdMap[$blog['member_id']];

        $model = $this->_getIPSImporterModel();

        $blogTitle = $this->_convertToUtf8($blog['blog_name'], true);

        $blog['username'] = $blog['name'];

        if ($blogTitle == '')
        {
            $blogTitle = $blog['username'] . '\'s Blog';
        }

        $blogOptions = array(
            'blog_review_required' => 0,
            'blog_allow_anon_reviews' => 0,
            'blog_allow_pros_cons' => 0,
        );

        $blog['blog_options'] = @serialize($blogOptions);

        $ubsBlog = array(
            'user_id' => $userId,
            'username' => $blog['username'],
            'blog_title' => $blogTitle,
            'blog_description' => $blog['blog_desc'],
            'blog_state' => 'visible',
            //'blog_create_date' => $blog['create_date'],
            'blog_last_update' => $blog['blog_last_udate'],
            //'blog_edit_date' => $blog['create_date'],
            'blog_view_count' => $blog['blog_num_views'],
            //'blog_entry_count' => $blog['entry_count'],
            //'last_blog_entry' => $blog['last_entry'],
            //'last_blog_entry_title' => '',
            //'last_blog_entry_id' => $blog['last_entry_id'],
            'blog_options' => $blog['blog_options']
        );

        $importedBlogId = $model->importBlog($blog['blog_id'], '', '', $ubsBlog);

        return $importedBlogId;
    }

    public function stepBlogEntries($start, array $options)
    {
        $options = array_merge(array(
            'limit' => 5,
            'max' => false
        ), $options);

	    $sDb = $this->_sourceDb;
	    $prefix = $this->_prefix;

        if ($options['max'] === false)
        {
            $options['max'] = $sDb->fetchOne('
				SELECT MAX(entry_id)
				FROM ' . $prefix . 'blog_entries
			');
        }

        $blogEntries = $sDb->fetchAll($sDb->limit(
            '
				SELECT blog_entry.*
				FROM ' . $prefix . 'blog_entries AS blog_entry
				WHERE blog_entry.entry_id > ' . $sDb->quote($start) . '
				ORDER BY blog_entry.entry_id ASC
			', $options['limit']
        ));
        if (!$blogEntries)
        {
            return true;
        }

        $next = 0;
        $total = 0;

        $options['ubs_desc_min_length'] = XenForo_Application::get('options')->ubsMinBlogDescriptionLength;

        $this->_userIdMap = $this->_importModel->getUserIdsMapFromArray($blogEntries, 'entry_author_id');

        foreach ($blogEntries AS $blogEntry)
        {
            $next = $blogEntry['entry_id'];

            $success = $this->_importBlogEntry($blogEntry, $options);
            if ($success)
            {
                $total++;
            }
        }

        $this->_session->incrementStepImportTotal($total);

        return array($next, $options, $this->_getProgressOutput($next, $options['max']));
    }

    protected function _importBlogEntry(array $blogEntry, array $options)
    {
        $sDb = $this->_sourceDb;
	    $prefix = $this->_prefix;

	    $userId = $this->_userIdMap[$blogEntry['entry_author_id']];

	    switch($blogEntry['entry_status'])
        {
            case 'published':
                $blogEntry['entry_status'] = 'visible';
                break;
            case 'draft':
                $blogEntry['entry_status'] = 'draft';
                break;
            default:
                $blogEntry['entry_status'] = 'moderated';
        }

        $blogEntry['entry'] = $this->_parseIPBoardBbCode($blogEntry['entry']);
        $model = $this->_getIPSImporterModel();

        $ubsBlogEntry = array(
            'blog_id' => $model->mapBlogId($blogEntry['blog_id']),
            'user_id' => $userId,
            'username' => $blogEntry['entry_author_name'],
            'title' => $this->_convertToUtf8($blogEntry['entry_name'], true),
            'description' => '',//substr($blogEntry['entry'], 0, $options['ubs_desc_min_length']),
            'blog_entry_state' => $blogEntry['entry_status'],
            'message' => $blogEntry['entry'],
            'publish_date' => $blogEntry['entry_date'],
            'last_update' => $blogEntry['entry_last_update'],
            'edit_date' => $blogEntry['entry_edit_time'],
            'blog_entry_view_count' => $blogEntry['entry_views'],
        );
        $importedBlogEntryId = $model->importBlogEntry($blogEntry['entry_id'], '', '', $ubsBlogEntry);

	    $newBlogEntry = $this->_getUBSBlogEntryModel()->getBlogEntryById($importedBlogEntryId);
	    //$this->_associateEntryWithCategories($newBlogEntry, $blogEntry['blog_id']);

        return $importedBlogEntryId;
    }

	protected function _associateEntryWithCategories($newBlogEntry, $oldBlogEntryId)
	{
		$sDb = $this->_sourceDb;
		$prefix = $this->_prefix;

		$oldCategories = $sDb->fetchAll('
			SELECT *
			FROM ' . $prefix . 'blog_category_mapping 
			WHERE map_entry_id = ?
			ORDER BY map_category_id
		', $oldBlogEntryId);

		if ($oldCategories)
		{
			$categoryIds = array();
			foreach ($oldCategories as $oldCategory)
			{
				$newCategoryId = $this->_mapCategoryId($oldCategory['category_id']);

				$categoryIds[] = $newCategoryId;
			}

			$this->_getUBSCategoryModel()->updateBlogEntryCategoryAssociation($newBlogEntry, $categoryIds);
		}
	}

    public function stepComments($start, array $options)
    {
        $options = array_merge(array(
            'limit' => 20,
            'max' => false
        ), $options);

        $sDb = $this->_sourceDb;
        $prefix = $this->_prefix;

        if ($options['max'] === false)
        {
            $options['max'] = $sDb->fetchOne('
				SELECT MAX(comment_id)
				FROM ' . $prefix . 'blog_comments
			');
        }

        $comments = $sDb->fetchAll($sDb->limit(
            '
				SELECT *
				FROM ' . $prefix . 'blog_comments
				WHERE comment_id > ' . $sDb->quote($start) . '
				ORDER BY comment_id
			', $options['limit']
        ));
        if (!$comments)
        {
            return true;
        }

        $next = 0;
        $total = 0;

        $this->_userIdMap = $this->_importModel->getUserIdsMapFromArray($comments, 'member_id');
        foreach ($comments AS $comment)
        {
            $next = $comment['comment_id'];

            $success = $this->_importComment($comment, $options);
            if ($success)
            {
                $total++;
            }
        }

        $this->_session->incrementStepImportTotal($total);

        return array($next, $options, $this->_getProgressOutput($next, $options['max']));
    }

    protected function _importComment(array $comment, array $options)
    {
        $model = $this->_getUBSImportersModel();

        $blogEntryId = $model->mapBlogEntryId($comment['entry_id']);

        $sDb = $this->_sourceDb;
        $prefix = $this->_prefix;

	    $userId = $this->_userIdMap[$comment['member_id']];

	    $ubsComment = array(
            'blog_entry_id' => $blogEntryId,
            'user_id' => $userId,
            'username' => $comment['member_name'],
            'comment_date' => $comment['comment_date'],
            'comment_state'	=> ($comment['comment_approved'] ? 'visible' : 'moderated'),
            'message' => $comment['comment_text']
        );

        $importedCommentId = $model->importComment($comment['comment_id'], $ubsComment);

        return $importedCommentId;
    }

    /**
     * Convert the given text to valid UTF-8
     *
     * @param string $string
     * @param boolean $entities Convert &lt; (and other) entities back to < characters
     *
     * @return string
     */
    protected function _convertToUtf8($string, $entities = null)
    {
        // note: assumes charset is ascii compatible
        if (preg_match('/[\x80-\xff]/', $string))
        {
            $newString = false;
            if (function_exists('iconv'))
            {
                $newString = @iconv($this->_charset, 'utf-8//IGNORE', $string);
            }
            if (!$newString && function_exists('mb_convert_encoding'))
            {
                $newString = @mb_convert_encoding($string, 'utf-8', $this->_charset);
            }
            $string = ($newString ? $newString : preg_replace('/[\x80-\xff]/', '', $string));
        }

        $string = utf8_unhtml($string, $entities);
        $string = preg_replace('/[\xF0-\xF7].../', '', $string);
        $string = preg_replace('/[\xF8-\xFB]..../', '', $string);
        return $string;
    }

    protected function _parseIPBoardBbCode($message, $autoLink = true)
    {
        $message = preg_replace('/<br( \/)?>(\r?\n)?/si', "\n", $message);
        $message = str_replace('&nbsp;' , ' ', $message);

        // handle the IPB media format
        if (stripos($message, '[media') !== false)
        {
            $message = $this->_parseIPBoardMediaCode($message);
        }

        $search = $this->_getIPBoardBBCodeReplacements();

        $message = preg_replace(array_keys($search), $search, $message);
        $message = strip_tags($message);

        return $this->_convertToUtf8($message, true);
    }

    protected function _parseIPBoardMediaCode($message)
    {
        return preg_replace_callback('#\[media[^\]]*\](http://.*)\[/media\]#siU', array($this, '_convertIPBoardMediaTag'), $message);
    }

    protected function _convertIPBoardMediaTag(array $regexMatches)
    {
        if ($embedHtml = XenForo_Helper_Media::convertMediaLinkToEmbedHtml($regexMatches[1]))
        {
            return $embedHtml;
        }
        else
        {
            return '[url]' . $regexMatches[1] . '[/url]';
        }
    }

    protected function _getIPBoardBBCodeReplacements()
    {
        return array(
            // HTML image <img /> smilies
            "/<img\s+src='([^']+)'\s+class='bbc_emoticon'\s+alt='([^']+)'\s+\/>/siU"
            => '\2',
            "/<img[^>]+src=(\"|')[^\"']+(\"|')[^>]*emoid=(\"|')([^\"']+)(\"|')[^>]*>/siU"
            => '\4',

            // translate attachments to something resembling our format in all cases (for quoted content in particular)
            "/\[attachment=(\d+):[^\]]+\]/siU"
            => '[ATTACH]\1.IPB[/ATTACH]',

            // strip anything after a comma in [FONT]
            '/\[(font)=(\'|"|)([^,\]]+)(,[^\]]*)(\2)\]/siU'
            => '[\1=\2\3\2]',

            '#<span [^>]*style="color:\s*([^";\\]]+?)[^"]*"[^>]*>(.*)</span>#siU' => '[COLOR=\\1]\\2[/COLOR]',
            '#<span [^>]*style="font-family:\s*([^";\\],]+?)[^"]*"[^>]*>(.*)</span>#siU' => '[FONT=\\1]\\2[/FONT]',
            '#<span [^>]*style="font-size:\s*([^";\\]]+?)[^"]*"[^>]*>(.*)</span>#siU' => '[SIZE=\\1]\\2[/SIZE]',
            '#<span[^>]*>(.*)</span>#siU' => '\\1',
            '#<(strong|b)>(.*)</\\1>#siU' => '[B]\\2[/B]',
            '#<(em|i)>(.*)</\\1>#siU' => '[I]\\2[/I]',
            '#<(u)>(.*)</\\1>#siU' => '[U]\\2[/U]',
            '#<(strike)>(.*)</\\1>#siU' => '[S]\\2[/S]',
            '#<a [^>]*href=(\'|")([^"\']+)\\1[^>]*>(.*)</a>#siU' => '[URL="\\2"]\\3[/URL]',
            '#<img [^>]*src="([^"]+)"[^>]*>#' => '[IMG]\\1[/IMG]',
            '#<img [^>]*src=\'([^\']+)\'[^>]*>#' => '[IMG]\\1[/IMG]',

            '#<(p|div) [^>]*style="text-align:\s*left;?">(.*)</\\1>(\r?\n)??#siU' => "[LEFT]\\2[/LEFT]\n",
            '#<(p|div) [^>]*style="text-align:\s*center;?">(.*)</\\1>(\r?\n)??#siU' => "[CENTER]\\2[/CENTER]\n",
            '#<(p|div) [^>]*style="text-align:\s*right;?">(.*)</\\1>(\r?\n)??#siU' => "[RIGHT]\\2[/RIGHT]\n",
            '#<(p|div) [^>]*class="bbc_left"[^>]*>(.*)</\\1>(\r?\n)??#siU' => "[LEFT]\\2[/LEFT]\n",
            '#<(p|div) [^>]*class="bbc_center"[^>]*>(.*)</\\1>(\r?\n)??#siU' => "[CENTER]\\2[/CENTER]\n",
            '#<(p|div) [^>]*class="bbc_right"[^>]*>(.*)</\\1>(\r?\n)??#siU' => "[RIGHT]\\2[/RIGHT]\n",

            '#<ul[^>]*>(.*)</ul>(\r?\n)??#siU' => "[LIST]\\1[/LIST]\n",
            '#<ol[^>]*>(.*)</ol>(\r?\n)??#siU' => "[LIST=1]\\1[/LIST]\n",
            '#<li[^>]*>(.*)</li>(\r?\n)??#siU' => "[*]\\1\n",

            '#<blockquote [^>]*class="ipsBlockquote"\s+data-author="([^"]+)"[^>]*>(.*)</blockquote>(\r?\n)??#siU' => '[QUOTE=\\1]\\2[/QUOTE]',
            '#<blockquote [^>]*class="ipsBlockquote"[^>]*>(.*)</blockquote>(\r?\n)??#siU' => '[QUOTE]\\1[/QUOTE]',

            '#<(p|pre)[^>]*>(&nbsp;|' . chr(0xC2) . chr(0xA0) .'|\s)*</\\1>(\r?\n)??#siU' => "\n",
            '#<p[^>]*>(.*)</p>(\r?\n)??#siU' => "\\1\n",
            '#<div[^>]*>(.*)</div>(\r?\n)??#siU' => "\\1\n",

            '#<pre[^>]*>(.*)</pre>(\r?\n)??#siU' => "[CODE]\\1[/CODE]\n",

            '#<!--.*-->#siU' => ''
        );
    }

    /**
     * @return NFLJ_UBS_Model_Importers
     */
    protected function _getUBSImportersModel()
    {
        $retainKeys = false;
        if (!empty($this->_config['retain_keys']))
        {
            $retainKeys = true;
        }

        /* @var $model NFLJ_UBS_Model_Importers */
        $model = XenForo_Model::create('NFLJ_UBS_Model_Importers');

        $model->retainKeys($retainKeys);

        return $model;
    }

    /**
     * @return NixFifty_IPS2UBS_Model_Blog
     */
    protected function _getIPSImporterModel()
    {
        $retainKeys = false;
        if (!empty($this->_config['retain_keys']))
        {
            $retainKeys = true;
        }

        /* @var $model NixFifty_IPS2UBS_Model_Blog */
        $model = XenForo_Model::create('NixFifty_IPS2UBS_Model_Blog');
        $model->retainKeys($retainKeys);

        return $model;
    }


	/**
	 * @return NFLJ_UBS_Model_BlogEntry
	 */
	protected function _getUBSBlogEntryModel()
	{
		return XenForo_Model::create('NFLJ_UBS_Model_BlogEntry');
	}

	/**
	 * @return NFLJ_UBS_Model_Blog
	 */
	protected function _getUBSBlogModel()
	{
		return XenForo_Model::create('NFLJ_UBS_Model_Blog');
	}

	/**
	 * @return NFLJ_UBS_Model_Category
	 */
	protected function _getUBSCategoryModel()
	{
		return XenForo_Model::create('NFLJ_UBS_Model_Category');
	}
}