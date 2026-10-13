<?php

namespace App\Services\Development;

use Illuminate\Http\UploadedFile;

/** Original, visibly fictional category-specific development previews. */
class DemoCatalogArtwork
{
    public function image(string $name, string $section, int $variant): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'templaara-demo-');
        $image = imagecreatetruecolor(1280, 720);
        if ($path === false || $image === false) {
            throw new \RuntimeException('Unable to create development artwork.');
        }
        $themes = ['Stock' => 0x325CB5, 'People' => 0x7F54AD, 'Pipeline' => 0x226E88, 'Finance' => 0x25866F, 'Field visits' => 0x2B6F9D, 'Orders' => 0xC05C55, 'Booking' => 0x5367C5, 'Projects' => 0x5C54A0, 'Analytics' => 0x287F8C, 'Tickets' => 0x4667A1];
        $accent = $themes[$section] ?? 0x325CB5;
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $text = function (int $x, int $y, string $value, int $size = 18, int $color = 0x253651) use ($image, $font): void {
            if (is_file($font)) {
                imagettftext($image, $size, 0, $x, $y + $size, $color, $font, $value);
            } else {
                imagestring($image, 5, $x, $y, $value, $color);
            }
        };
        $box = fn (int $x, int $y, int $w, int $h, int $color) => imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $color);
        imagefill($image, 0, 0, 0xEDF2FA);
        $box(0, 0, 190, 720, $accent);
        $text(22, 28, 'TEMPLAARA', 19, 0xFFFFFF);
        foreach (['Overview', $section, 'Workspace', 'Activity', 'Settings'] as $i => $label) {
            $text(22, 120 + $i * 65, $label, 16, 0xFFFFFF);
        }
        $box(190, 0, 1090, 76, 0xFFFFFF);
        $text(224, 23, $name, 24);
        $text(224, 98, $section.' / illustrative workspace', 19, $accent);
        $text(224, 132, 'FICTIONAL DEVELOPMENT PREVIEW - NOT FUNCTIONING SOFTWARE', 13, 0x63728B);
        $rows = match ($section) {
            'Stock' => ['Product catalog', 'Stock movement', 'Purchase tracking', 'Billing workspace'],
            'People' => ['Employee records', 'Attendance workspace', 'Payroll preparation', 'Team overview'],
            'Pipeline' => ['New conversations', 'Follow up', 'Opportunities'],
            'Finance' => ['Invoice register', 'Expense workspace', 'Finance administration', 'Business records'],
            'Field visits' => ['Service visits', 'Technician planning', 'Visit schedule', 'Service records'],
            'Orders' => ['Merchandising', 'Product catalog', 'Fulfillment workspace', 'Order records'],
            'Booking' => ['Appointments', 'Availability', 'Booking schedule', 'Customer records'],
            'Projects' => ['Planned', 'In progress', 'Ready'],
            'Analytics' => ['Operations reports', 'Business activity', 'Report workspace', 'Summary view'],
            'Tickets' => ['Customer requests', 'Response workspace', 'Ticket records', 'Service overview'],
            default => ['Daily workspace', 'Team planning', 'Activity overview'],
        };
        if (in_array($section, ['Pipeline', 'Projects'], true)) {
            foreach ($rows as $i => $label) {
                $x = 224 + $i * 340;
                $box($x, 198, 316, 442, 0xFFFFFF);
                $box($x, 198, 316, 5, $accent);
                $text($x + 18, 218, $label, 18);
                for ($j = 0; $j < 3; $j++) {
                    $box($x + 16, 274 + $j * 110, 284, 88, 0xF0F4FA);
                    $text($x + 30, 290 + $j * 110, $section === 'Pipeline' ? 'Conversation workspace' : 'Delivery planning', 14);
                    $box($x + 30, 326 + $j * 110, 170 - ($i * 17), 7, $accent);
                }
            }
        } elseif (in_array($section, ['Booking', 'Field visits'], true)) {
            $box(224, 198, 1000, 442, 0xFFFFFF);
            $text(246, 214, $section === 'Booking' ? 'Appointment availability' : 'Technician visit planning', 20);
            for ($j = 0; $j < 21; $j++) {
                $x = 246 + ($j % 7) * 138;
                $y = 268 + intdiv($j, 7) * 112;
                imagerectangle($image, $x, $y, $x + 126, $y + 100, 0xCBD8E9);
                $text($x + 10, $y + 10, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$j % 7], 12);
                if (($j + $variant) % 3 === 0) {
                    $box($x + 8, $y + 40, 110, 38, $accent);
                    $text($x + 15, $y + 47, $section === 'Booking' ? 'Appointment' : 'Field visit', 11, 0xFFFFFF);
                }
            }
        } elseif ($section === 'Analytics') {
            $box(224, 198, 638, 442, 0xFFFFFF);
            $text(246, 218, 'Operations report preview', 20);
            for ($j = 0; $j < 8; $j++) {
                $h = 70 + (($j * 43 + $variant * 17) % 210);
                $box(258 + $j * 72, 574 - $h, 40, $h, $j === 3 ? 0xF16B58 : $accent);
            }$text(246, 600, 'Illustrative shapes - no real marketplace metrics', 12, 0x63728B);
            foreach (array_slice($rows, 0, 3) as $i => $r) {
                $box(890, 198 + $i * 151, 334, 132, 0xFFFFFF);
                $text(910, 218 + $i * 151, $r, 17);
                $box(910, 260 + $i * 151, 240, 8, $accent);
            }
        } elseif ($section === 'Orders') {
            foreach ($rows as $i => $r) {
                $x = 224 + ($i % 2) * 512;
                $y = 198 + intdiv($i, 2) * 232;
                $box($x, $y, 488, 212, 0xFFFFFF);
                $box($x + 20, $y + 20, 100, 100, $accent);
                $text($x + 140, $y + 27, $r, 17);
                $box($x + 140, $y + 71, 230, 9, 0xCAD8ED);
                $box($x + 140, $y + 99, 170, 9, 0xCAD8ED);
                $text($x + 20, $y + 158, 'Illustrative online retail workspace', 13, 0x63728B);
            }
        } else {
            $box(224, 198, 1000, 442, 0xFFFFFF);
            $text(248, 218, match ($section) {
                'Stock' => 'Inventory & purchasing','People' => 'Employee & attendance records','Finance' => 'Invoices & expenses','Tickets' => 'Customer support workspace',default => $section
            }, 22);
            foreach ($rows as $i => $r) {
                $y = 282 + $i * 80;
                $box(246, $y, 956, 62, $i % 2 ? 0xFFFFFF : 0xF1F5FC);
                $box(260, $y + 12, 38, 38, $accent);
                $text(320, $y + 17, $r, 18);
                $text(774, $y + 19, match ($section) {
                    'Stock' => ['Catalog', 'Stock', 'Purchases', 'Billing'][$i],'People' => ['People', 'Attendance', 'Payroll', 'Team'][$i],'Finance' => ['Invoices', 'Expenses', 'Records', 'Overview'][$i],default => 'Service'
                }, 14, 0x63728B);
                $box(1050, $y + 20, 120, 8, $accent);
            }
        }
        $text(224, 675, 'DEVELOPMENT ONLY / original Templaara artwork / '.($variant === 0 ? 'Cover' : 'Preview '.$variant), 13, 0x63728B);
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'workspace.png', 'image/png', null, true);
    }
}
