<?php
declare(strict_types=1);
namespace Omnigo;

/** Shared layout standard with per-account color palettes. */
final class WorkspaceAppearance
{
    public const STANDARD=['theme'=>'parliament','fontSize'=>'standard','fontFamily'=>'system','density'=>'comfortable'];
    public const THEMES=['parliament','classic-navy','navy','light','burgundy','petrol-smoke','graphite-copper'];

    public static function defaultTheme(array $user=[]):string
    {
        $name=trim(preg_replace('/\s+/u',' ',(string)($user['operatorName']??$user['name']??''))??'');
        return mb_strtolower($name)==='aysun yurdakul'?'classic-navy':'parliament';
    }

    public static function normalize(array $preferences,array $user=[]):array
    {
        $theme=$preferences['theme']??null;
        if($theme==='navy')$theme=self::defaultTheme($user);
        if(!in_array($theme,self::THEMES,true))$theme=self::defaultTheme($user);
        return array_replace($preferences,self::STANDARD,['theme'=>$theme]);
    }
}
