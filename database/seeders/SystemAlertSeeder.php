<?php

namespace Database\Seeders;

use App\Models\SystemAlert;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SystemAlertSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $alerts = [
            [
                'title' => 'מלאי דיגיטלי XYZ במלאי נמוך',
                'message' => 'השלמת העדכון הדיגיטלי נדרשת לפני סוף היום כדי למנוע חסר במלאי.',
                'severity' => 'warning',
                'category' => 'inventory',
                'icon' => 'alert-triangle',
                'audience' => 'admin',
                'status' => 'active',
                'is_sticky' => true,
                'published_at' => $now->copy()->subHours(2),
                'metadata' => [
                    'target' => 'products/low-stock',
                ],
            ],
            [
                'title' => 'משלוח #7845 מתעכב',
                'message' => 'המשלוח האחרון ללקוח חיים כהן עוכב בסניף המרכזי ומצריך מעקב.',
                'severity' => 'info',
                'category' => 'shipment',
                'icon' => 'package-search',
                'audience' => 'admin',
                'status' => 'active',
                'is_sticky' => false,
                'published_at' => $now->copy()->subHours(5),
                'metadata' => [
                    'order_number' => 'SEED-ORD-002',
                    'tracking_number' => 'TRK-SEED-2001',
                ],
            ],
            [
                'title' => 'חריגה מה-OBLIGO לסוחר "חנות אלקטרוניקה בע״מ"',
                'message' => 'הסוחר עבר את מגבלת האובליגו. מערכת הגבייה הודיעה על חריגה שיש להסדיר באופן ידני.',
                'severity' => 'danger',
                'category' => 'finance',
                'icon' => 'alert-octagon',
                'audience' => 'admin',
                'status' => 'active',
                'is_sticky' => true,
                'published_at' => $now->copy()->subDays(1)->addHours(3),
                'metadata' => [
                    'merchant_id' => 2,
                ],
                'action_label' => 'צפה בדוח חיובים',
                'action_url' => '/admin/finance/obligo',
            ],
            [
                'title' => 'אוזניות גיימינג ABC במלאי נמוך',
                'message' => 'נותרו 8 יחידות במחסן הצפון. מומלץ לבצע הזמנה.',
                'severity' => 'warning',
                'category' => 'inventory',
                'icon' => 'headphones',
                'audience' => 'admin',
                'status' => 'active',
                'is_sticky' => false,
                'published_at' => $now->copy()->subDays(2)->addHours(1),
                'metadata' => [
                    'sku' => 'AUD-GAME-004',
                ],
            ],
            [
                'title' => 'תשלום חדש התקבל ממסור "סופר דיגיטל"',
                'message' => 'התקבל תשלום על סך ₪12,500 עבור חשבונית #INV-2398.',
                'severity' => 'success',
                'category' => 'payments',
                'icon' => 'credit-card',
                'audience' => 'admin',
                'status' => 'active',
                'is_sticky' => false,
                'published_at' => $now->copy()->subHours(12),
                'metadata' => [
                    'invoice_number' => 'INV-2398',
                    'amount' => 12500,
                    'currency' => 'ILS',
                ],
            ],
        ];

        foreach ($alerts as $alert) {
            SystemAlert::updateOrCreate(
                ['title' => $alert['title']],
                $alert
            );
        }
    }
}
