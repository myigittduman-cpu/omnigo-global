<?php
declare(strict_types=1);
/** Legacy stored grants remain fixtures; the Omnigo-only role boundary must override them. */
function allowFinancialVisibility(\Omnigo\Database $db,int $company):void
{
 $row=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);$profile=$row?json_decode($row['payload'],true):[];
 $profile['workspacePolicy']['defaults']['visibility']=['view'=>true,'viewCancellations'=>true,'viewReturns'=>true];
 foreach($profile['workspacePolicy']['users']??[] as &$grants)$grants['visibility']=['view'=>true,'viewCancellations'=>true,'viewReturns'=>true];unset($grants);
 if($row)$db->run('UPDATE company_profiles SET payload=? WHERE company_id=?',[json_encode($profile),$company]);
 else $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,json_encode($profile)]);
}
