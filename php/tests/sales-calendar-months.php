<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\SalesCalendar;
function checkMonths(string $from,string $through,array $expected):void
{
    if(SalesCalendar::months($from,$through)!==$expected)throw new RuntimeException('Ay kapsamı hatalı: '.$from.' → '.$through);
}
checkMonths('2026-10-31T20:00:00Z','2026-11-01T20:00:00Z',['2026-10','2026-11']);
checkMonths('2026-01-31T12:00:00+03:00','2026-03-01T12:00:00+03:00',['2026-01','2026-02','2026-03']);
checkMonths('2028-01-31T12:00:00+03:00','2028-03-01T12:00:00+03:00',['2028-01','2028-02','2028-03']);
checkMonths('2026-12-31T20:30:00Z','2026-12-31T21:30:00Z',['2026-12','2027-01']);
checkMonths('2026-10-31T21:30:00Z','2026-11-01T00:00:00Z',['2026-11']);
checkMonths('2026-11-01T00:00:00+03:00','2026-11-01T00:00:00+03:00',['2026-11']);
echo "Ay kapsamı: ay sonu, Şubat, artık yıl, yıl geçişi ve Türkiye saat dilimi geçti.\n";
