curl --location 'http://localhost:8000/api/plugin/orders' \
--header 'Content-Type: application/json' \
--header 'Accept: application/json' \
--header 'Authorization: Bearer 192|m4lxrtNUkJ3VN6t3efhs4ha0bNZjuYENDTz5aSGJe9fcfdc0' \
--data-raw '{
  "site_url": "https://www.kfitzkfotz.co.il/",
  "order_number": "PLUGIN-TEST-55555",
  "total": 399.7,
  "items": [
    {
      "product_id": "1",
      "variation_id": "1",
      "quantity": 1
    },
    {
      "product_id": "2",
      "variation_id": "2",
      "quantity": 2
    }
  ],
  "customer": {
    "name": "נועם הלקוח",
    "email": "customer@example.com",
    "phone": "+972500000000",
    "address": {
      "line1": "תובל 32",
      "city": "רמת גן",
      "state": "מחוז תל אביב",
      "zip": "52522",
      "country": "IL"
    },
    "notes": "לקוח חוזר מהחנות המקוונת"
  },
  "source": "shopify",
  "status": "paid",
  "shipping": {
    "type": "delivery",
    "method": "standard",
    "cost": 0
  }
}'