<?php

class NixFifty_IPS2UBS_Listen
{
    public static function extendImportModel($class, array &$extend)
    {
        XenForo_Model_Import::$extraImporters[] = 'NixFifty_IPS2UBS_Importer_Blogs';
    }
}