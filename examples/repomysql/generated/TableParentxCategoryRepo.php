<?php
/** @noinspection PhpUnused
 * @noinspection ReturnTypeCanBeDeclaredInspection
 */
namespace repomysql;
use mysql\repomodel\TableParentxCategoryModel;
use Exception;

/**
 * Generated by PdoOne Version 2.0 Date generated Wed, 12 Aug 2020 21:21:32 -0400. 
 * EDIT THIS CODE.
 * @copyright (c) Jorge Castro C. MIT License  https://github.com/EFTEC/PdoOne
 * Class TableParentxCategoryRepo
 * <pre>
 * $code=$pdoOne->generateCodeClassRepo(''TableParentxCategory'',''repomysql'','array('TableParent'=>'TableParentRepo','TableChild'=>'TableChildRepo','TableGrandChild'=>'TableGrandChildRepo','TableGrandChildTag'=>'TableGrandChildTagRepo','TableParentxCategory'=>'TableParentxCategoryRepo','TableCategory'=>'TableCategoryRepo','TableParentExt'=>'TableParentExtRepo',)',''mysql\repomodel\TableParentxCategoryModel'');
 * </pre>
 */
class TableParentxCategoryRepo extends AbstractTableParentxCategoryRepo
{
    const ME=__CLASS__; 
    const MODEL= TableParentxCategoryModel::class;
  
    
}