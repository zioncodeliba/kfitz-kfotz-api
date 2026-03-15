<?php

namespace Tests\Unit;

use App\Services\ChitaShipmentService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChitaShipmentServiceTest extends TestCase
{
    public function test_it_maps_full_address_details_to_chita_address_fields(): void
    {
        config([
            'chita.token' => 'test-token',
            'chita.base_url' => 'https://chita.example/Request.aspx',
            'chita.app_name' => 'run',
            'chita.create_program' => 'ship_create_anonymous',
            'chita.customer_number' => '26161',
            'chita.shipment_type' => '12345',
            'chita.shipment_stage' => '1',
            'chita.response_type' => 'XML',
            'chita.pickup_point_assign' => 'N',
        ]);

        Http::fake([
            'https://chita.example/Request.aspx*' => Http::response(<<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<root>
    <result>ok</result>
    <mydata>
        <shgiya_yn><![CDATA[n]]></shgiya_yn>
        <message><![CDATA[]]></message>
        <answer>
            <ship_create_num><![CDATA[94040383]]></ship_create_num>
        </answer>
    </mydata>
</root>
XML, 200),
        ]);

        $result = app(ChitaShipmentService::class)->createShipment([
            'order_id' => 1,
            'shipping_type' => 'delivery',
            'company_name' => 'KFITZ',
            'destination' => [
                'name' => 'Dana Levi',
                'city' => 'הוד השרון',
                'street' => 'נצח ישראל 68',
                'street_number' => '68',
                'entrance' => 'ב',
                'floor' => '3',
                'apartment' => '12',
                'phone' => '0501234567',
                'secondary_phone' => '0527654321',
                'email' => 'dana@example.com',
            ],
            'shipping_units' => [
                [
                    'shipping_size' => 'regular',
                    'quantity' => 1,
                ],
            ],
            'reference' => 'ORDER-1',
            'reference_secondary' => '1',
            'order_skus' => 'SKU-1-1',
        ]);

        $this->assertSame('94040383', $result['tracking_number']);

        Http::assertSent(function ($request) {
            $url = $request->url();
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $arguments = explode(',', (string) ($query['ARGUMENTS'] ?? ''));

            return str_starts_with($url, 'https://chita.example/Request.aspx')
                && ($arguments[14] ?? null) === '-Aנצח ישראל'
                && ($arguments[15] ?? null) === '-A68'
                && ($arguments[16] ?? null) === '-Aב'
                && ($arguments[17] ?? null) === '-A3'
                && ($arguments[18] ?? null) === '-A12';
        });
    }
}
