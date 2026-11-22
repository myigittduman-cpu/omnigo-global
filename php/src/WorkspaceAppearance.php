<?php
declare(strict_types=1);
namespace Omnigo;

/** Shared layout standard with per-account color palettes. */
final class WorkspaceAppearance
{
    public const STANDARD=['theme'=>'navy','fontSize'=>'standard','fontFamily'=>'system','density'=>'comfortable'];
    public const THEMES=['navy','light','burgundy','petrol-smoke','graphite-copper'];

    public static function normalize(array $preferences):array
    {
        $theme=$preferences['theme']??null;
        if(!in_array($theme,self::THEMES,true))$theme=self::STANDARD['theme'];
        return array_replace($preferences,self::STANDARD,['theme'=>$theme]);
    }
}
