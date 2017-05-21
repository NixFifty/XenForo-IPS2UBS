<?php

class NixFifty_IPS2UBS_Model_Blog extends XenForo_Model
{
    /**
     * If true, wherever possible, keep the existing data primary key
     *
     * @var boolean
     */
    protected $_retainKeys = false;

	public function importCategory($oldId, array $info, $contentType = 'ubs_category')
	{
		XenForo_Db::beginTransaction();

		/* @var $dw NFLJ_UBS_DataWriter_Category */
		$dw = XenForo_DataWriter::create('NFLJ_UBS_DataWriter_Category');
		$dw->setImportMode(true);

		if ($contentType == 'ubs_category')
		{
			if ($this->_retainKeys)
			{
				$dw->set('category_id', $oldId);
			}
		}

		$dw->bulkSet($info);
		if ($dw->save())
		{
			$im = $this->_getImportModel();

			$newId = $dw->get('category_id');

			$im->logImportData($contentType, $oldId, $newId);

			$this->_getUBSCategoryModel()->rebuildCategoryMaterializedOrder();
			$this->_getUBSCategoryModel()->rebuildCategoriesListCache();
		}
		else
		{
			$newId = false;
		}

		XenForo_Db::commit();

		return $newId;
	}

	public function importBlog($oldId, $tempFile, $contentType = '', array $ubsBlog = array(), array $xfAttachment = array(), array $xfAttachmentData = array())
	{
		XenForo_Db::beginTransaction();

		$newId = false;
		if ($ubsBlog)
		{
			/** @var $blogDw NFLJ_UBS_DataWriter_Blog */
			$blogDw = XenForo_DataWriter::create('NFLJ_UBS_DataWriter_Blog');

			$blogDw->setImportMode(true);

			if ($this->_retainKeys && is_int($oldId) && $oldId > 0)
			{
				$blogDw->set('blog_id', $oldId);
			}

			$blogDw->bulkSet($ubsBlog);
			if ($blogDw->save())
			{
				$newId = $blogDw->get('blog_id');

				$this->_getImportModel()->logImportData('ubs_blog', $oldId, $newId);
			}

			$blog = $blogDw->getMergedData();
		}

		if ($newId)
		{
			XenForo_Db::commit();
		}
		else
		{
			XenForo_Db::rollback();
		}

		return $newId;
	}

    public function importBlogEntry($oldId, $tempFile, $contentType = '', array $ubsBlogEntry = array(), array $xfAttachment = array(), array $xfAttachmentData = array())
    {
        XenForo_Db::beginTransaction();

        $newId = false;
        if ($ubsBlogEntry)
        {
            /** @var $blogEntryDw NFLJ_UBS_DataWriter_BlogEntry */
            $blogEntryDw = XenForo_DataWriter::create('NFLJ_UBS_DataWriter_BlogEntry');

            $blogEntryDw->setImportMode(true);

            if ($this->_retainKeys)
            {
                $blogEntryDw->set('blog_entry_id', $oldId);
            }

            $blogEntryDw->bulkSet($ubsBlogEntry);
            if ($blogEntryDw->save())
            {
                $newId = $blogEntryDw->get('blog_entry_id');

                $this->_getImportModel()->logImportData('ubs_blog_entry', $oldId, $newId);
            }

            $blogEntry = $blogEntryDw->getMergedData();
        }

        if ($newId)
        {
            XenForo_Db::commit();
        }
        else
        {
            XenForo_Db::rollback();
        }

        return $newId;
    }

    public function mapBlogId($id, $default = null)
    {
        $logTable = (defined('IMPORT_LOG_TABLE') ? IMPORT_LOG_TABLE : 'xf_import_log');

        if ($logTable != 'xf_import_log')
        {
            $ids = $this->getImportContentMap('ubs_blog', $id, 'xf_import_log');
            return ($ids ? reset($ids) : $default);
        }

        $ids = $this->_getImportModel()->getImportContentMap('ubs_blog', $id);
        return ($ids ? reset($ids) : $default);
    }

    /**
     * Gets an import content map to map old IDs to new IDs for the given content type.
     *
     * @param string $contentType
     * @param array $ids
     *
     * @return array
     */
    public function getImportContentMap($contentType, $ids = false, $logTable)
    {
        $db = $this->_getDb();

        if ($ids === false)
        {
            return $db->fetchPairs('
				SELECT old_id, new_id
				FROM ' . $logTable . '
				WHERE content_type = ?
			', $contentType);
        }

        if (!is_array($ids))
        {
            $ids = array($ids);
        }
        if (!$ids)
        {
            return array();
        }

        $final = array();
        if (isset($this->_contentMapCache[$contentType]))
        {
            $lookup = $this->_contentMapCache[$contentType];
            foreach ($ids AS $key => $id)
            {
                if (isset($lookup[$id]))
                {
                    $final[$id] = $lookup[$id];
                    unset($ids[$key]);
                }
            }
        }

        if (!$ids)
        {
            return $final;
        }

        foreach ($ids AS &$id)
        {
            $id = strval($id);
        }

        $merge = $db->fetchPairs('
			SELECT old_id, new_id
			FROM ' . $logTable . '
			WHERE content_type = ?
				AND old_id IN (' . $db->quote($ids) . ')
		', $contentType);

        if (isset($this->_contentMapCache[$contentType]))
        {
            $this->_contentMapCache[$contentType] += $merge;
        }
        else
        {
            $this->_contentMapCache[$contentType] = $merge;
        }

        return $final + $merge;
    }

    public function checkImportLogTableExists($logTableName)
    {
        return $this->_getDb()->fetchOne('
			SHOW TABLES
			LIKE ' . $this->_getDb()->quote($logTableName));
    }

    /**
     * Sets the value of the $_retainKeys option, in order to retain the existing keys where possible
     *
     * @param boolean $retainKeys
     */
    public function retainKeys($retainKeys)
    {
        $this->_retainKeys = ($retainKeys ? true : false);
    }

    /**
     * @return XenForo_Model_Import
     */
    protected function _getImportModel()
    {
        return $this->getModelFromCache('XenForo_Model_Import');
    }

    /**
     * @return NFLJ_UBS_Model_BlogEntry
     */
    protected function _getUBSBlogEntryModel()
    {
        return $this->getModelFromCache('NFLJ_UBS_Model_BlogEntry');
    }

    /**
     * @return NFLJ_UBS_Model_Blog
     */
    protected function _getUBSBlogModel()
    {
        return $this->getModelFromCache('NFLJ_UBS_Model_Blog');
    }

    /**
     * @return NFLJ_UBS_Model_Category
     */
    protected function _getUBSCategoryModel()
    {
        return $this->getModelFromCache('NFLJ_UBS_Model_Category');
    }
}