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
                'event_key' => 'merchant.payment.received',
                'name' => 'תשלום סוחר התקבל',
                'subject' => 'תשלום התקבל עבור {{merchant.business_name}} ({{payment.amount}} {{payment.currency}})',
                'body_html' => '<p>שלום {{merchant.name}},</p><p>התקבל תשלום בסך <strong>{{payment.amount}} {{payment.currency}}</strong>.</p><p>חודש: {{payment.payment_month}} | אמצעי: {{payment.method}} | מקור: {{context.source}}</p>',
                'body_text' => 'שלום {{merchant.name}}, התקבל תשלום בסך {{payment.amount}} {{payment.currency}}. חודש: {{payment.payment_month}} | אמצעי: {{payment.method}} | מקור: {{context.source}}',
                'default_recipients' => [
                    'to' => ['{{merchant.email}}'],
                ],
                'metadata' => [
                    'placeholders' => [
                        'merchant.name',
                        'merchant.email',
                        'merchant.business_name',
                        'payment.amount',
                        'payment.currency',
                        'payment.payment_month',
                        'payment.method',
                        'payment.reference',
                        'payment.note',
                        'context.source',
                    ],
                    'sms' => [
                        'enabled' => true,
                        'message' => 'תשלום התקבל בסך {{payment.amount}} {{payment.currency}} עבור {{merchant.business_name}}',
                        'recipients' => ['0504071205'],
                        'sender' => 'Kfitz',
                    ],
                ],
            ],
            [
                'event_key' => 'merchant.payment.bank_transfer.requested',
                'name' => 'בקשת תשלום בהעברה בנקאית',
                'subject' => 'בקשת תשלום בהעברה בנקאית — {{submission.amount}} {{submission.currency}}',
                'body_html' => '<p>שלום {{merchant.name}},</p><p>נקלטה בקשת תשלום בהעברה בנקאית בסך <strong>{{submission.amount}} {{submission.currency}}</strong>.</p><p>חודש: {{submission.payment_month}} | סטטוס: {{submission.status}} | מקור: {{context.source}}</p>',
                'body_text' => 'שלום {{merchant.name}}, נקלטה בקשת תשלום בהעברה בנקאית בסך {{submission.amount}} {{submission.currency}}. חודש: {{submission.payment_month}} | סטטוס: {{submission.status}} | מקור: {{context.source}}',
                'default_recipients' => [
                    'to' => ['{{merchant.email}}'],
                ],
                'metadata' => [
                    'placeholders' => [
                        'merchant.name',
                        'merchant.email',
                        'merchant.business_name',
                        'submission.amount',
                        'submission.currency',
                        'submission.payment_month',
                        'submission.status',
                        'submission.reference',
                        'submission.note',
                        'context.source',
                    ],
                    'sms' => [
                        'enabled' => true,
                        'message' => 'נקלטה בקשת תשלום בהעברה בנקאית {{submission.amount}} {{submission.currency}} עבור {{merchant.business_name}}',
                        'recipients' => ['0504071205'],
                        'sender' => 'Kfitz',
                    ],
                ],
            ],
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
                'body_html' => '<p>המוצר <strong>{{product.name}}</strong> חזר למלאי.</p><p>מק״ט: {{product.sku}} | כמות במלאי: {{product.stock_quantity}}</p>',
                'body_text' => 'המוצר {{product.name}} חזר למלאי. מק״ט: {{product.sku}} | כמות במלאי: {{product.stock_quantity}}',
                'default_recipients' => [
                    'to' => ['{{recipient.email}}'],
                ],
                'metadata' => [
                    'placeholders' => [
                        'recipient.email',
                        'product.name',
                        'product.sku',
                        'product.stock_quantity',
                        'product.min_stock_alert',
                        'product.price',
                        'category.name',
                    ],
                    'sms' => [
                        'enabled' => true,
                        'message' => 'המוצר {{product.name}} חזר למלאי (כמות: {{product.stock_quantity}})',
                        'recipients' => ['0504071205'],
                        'sender' => 'Kfitz',
                    ],
                ],
            ],
            [
                'event_key' => 'product.out_of_stock',
                'name' => 'מוצר אזל מהמלאי',
                'subject' => 'המוצר {{product.name}} אזל מהמלאי',
                'body_html' => '<p>המוצר <strong>{{product.name}}</strong> אזל מהמלאי.</p><p>מק״ט: {{product.sku}} | כמות במלאי: {{product.stock_quantity}}</p>',
                'body_text' => 'המוצר {{product.name}} אזל מהמלאי. מק״ט: {{product.sku}} | כמות במלאי: {{product.stock_quantity}}',
                'default_recipients' => [
                    'to' => ['{{recipient.email}}'],
                ],
                'metadata' => [
                    'placeholders' => [
                        'recipient.email',
                        'product.name',
                        'product.sku',
                        'product.stock_quantity',
                        'product.min_stock_alert',
                        'product.price',
                        'category.name',
                    ],
                    'sms' => [
                        'enabled' => true,
                        'message' => 'המוצר {{product.name}} אזל מהמלאי',
                        'recipients' => ['0504071205'],
                        'sender' => 'Kfitz',
                    ],
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
