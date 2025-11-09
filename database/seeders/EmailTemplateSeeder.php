<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EmailTemplate;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'event_key' => 'order.created',
                'name' => 'יצירת הזמנה חדשה',
                'subject' => 'הזמנה חדשה מספר {{order.id}} נקלטה בהצלחה',
                'body_html' => '<p>שלום {{customer.name}},</p><p>הזמנתך מספר <strong>{{order.id}}</strong> נקלטה בהצלחה. נספר לך כשסטטוס המשלוח יתעדכן.</p>',
                'body_text' => 'שלום {{customer.name}}, הזמנתך מספר {{order.id}} נקלטה בהצלחה.',
                'default_recipients' => [
                    'to' => ['{{customer.email}}'],
                ],
                'metadata' => [
                    'placeholders' => ['customer.name', 'customer.email', 'order.id'],
                ],
            ],
            [
                'event_key' => 'order.shipped',
                'name' => 'הזמנה יצאה למשלוח',
                'subject' => 'ההזמנה שלך בדרך! ({{order.id}})',
                'body_html' => '<p>שלום {{customer.name}},</p><p>ההזמנה שלך יצאה למשלוח עם חברת {{shipment.carrier}}. מספר מעקב: {{shipment.tracking_number}}.</p>',
                'body_text' => 'שלום {{customer.name}}, ההזמנה שלך יצאה למשלוח. מספר מעקב: {{shipment.tracking_number}}.',
                'default_recipients' => [
                    'to' => ['{{customer.email}}'],
                ],
                'metadata' => [
                    'placeholders' => ['customer.name', 'shipment.tracking_number', 'shipment.carrier'],
                ],
            ],
            [
                'event_key' => 'product.back_in_stock',
                'name' => 'מוצר חזר למלאי',
                'subject' => 'המוצר {{product.name}} חזר למלאי',
                'body_html' => '<p>שלום {{customer.name}},</p><p>המוצר {{product.name}} חזר למלאי ואפשר להזמין שוב.</p>',
                'body_text' => 'המוצר {{product.name}} חזר למלאי.',
                'default_recipients' => [
                    'to' => ['{{customer.email}}'],
                ],
                'metadata' => [
                    'placeholders' => ['customer.name', 'product.name'],
                ],
            ],
        ];

        foreach ($templates as $template) {
            EmailTemplate::updateOrCreate(
                ['event_key' => $template['event_key']],
                $template
            );
        }
    }
}
