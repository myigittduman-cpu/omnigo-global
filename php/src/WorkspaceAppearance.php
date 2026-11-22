<?php
declare(strict_types=1);
namespace Omnigo;

/** Shared layout standard with per-account color palettes. */
final class WorkspaceAppearance
{
    public const STANDARD=['theme'=>'classic-navy','fontSize'=>'standard','fontFamily'=>'system','density'=>'comfortable'];
    public const THEMES=['parliament','classic-navy','navy','light','burgundy','petrol-smoke','graphite-copper'];

    public static function defaultTheme(array $user=[]):string
    {
        return 'classic-navy';
    }

    public static function normalize(array $preferences,array $user=[]):array
    {
        $theme=$preferences['theme']??null;
        // Parliament was a short-lived global rollout. Keep saved payloads intact,
        // but resolve both stale variants to the approved classic navy appearance.
        if(in_array($theme,['navy','parliament'],true))$theme=self::defaultTheme($user);
        if(!in_array($theme,self::THEMES,true))$theme=self::defaultTheme($user);
        return array_replace($preferences,self::STANDARD,['theme'=>$theme]);
    }
}
