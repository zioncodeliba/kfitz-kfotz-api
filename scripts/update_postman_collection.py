import json
from pathlib import Path


BASE_URL_VARIABLE = "{{base_url}}"
SAMPLE_PHONE = "+972500000000"
SAMPLE_EMAIL = "customer@example.com"
SAMPLE_ADDRESS = {
    "name": "רות כהן",
    "company": "חברת לקוח בע\"מ",
    "phone": SAMPLE_PHONE,
    "street": "דרך השלום 45",
    "city": "תל אביב",
    "zip": "61000",
    "country": "IL",
}
SAMPLE_BILLING_ADDRESS = {
    "name": "רות כהן",
    "phone": SAMPLE_PHONE,
    "street": "הרצל 12",
    "city": "פתח תקווה",
    "zip": "49300",
    "country": "IL",
}
SAMPLE_SHIPPING_ADDRESS = {
    "name": "רות כהן",
    "phone": SAMPLE_PHONE,
    "street": "תובל 32",
    "city": "רמת גן",
    "zip": "52522",
    "country": "IL",
    "notes": "לתאם מסירה מראש",
}
SAMPLE_BANK_DETAILS = {
    "bank_name": "הפועלים",
    "branch_number": "123",
    "account_number": "456789",
    "account_name": "Merchant LTD",
}
PLUGIN_CUSTOMER_ADDRESS = {
    "line1": "תובל 32",
    "city": "רמת גן",
    "state": "מחוז תל אביב",
    "zip": "52522",
    "country": "IL",
}


def build_url(path_segments, query=None):
    raw_path = "/".join(path_segments)
    raw_url = f"{BASE_URL_VARIABLE}/{raw_path}" if raw_path else BASE_URL_VARIABLE
    url = {
        "raw": raw_url,
        "host": [BASE_URL_VARIABLE],
        "path": path_segments,
    }
    if query:
        url["query"] = query
    return url


def raw_body(payload):
    return {
        "mode": "raw",
        "raw": json.dumps(payload, indent=2, ensure_ascii=False),
        "options": {"raw": {"language": "json"}},
    }


def form_data_body(fields):
    return {
        "mode": "formdata",
        "formdata": fields,
    }


def login_test_script(role=None):
    script_lines = [
        "const json = pm.response.json();",
        "const data = json && json.data ? json.data : {};",
        "const token = data.token;",
        "pm.test('Token received', function () {",
        "    pm.expect(token, 'token').to.exist;",
        "});",
        "if (token) {",
        "    pm.collectionVariables.set('auth_token', token);",
    ]
    if role:
        script_lines.append(f"    pm.collectionVariables.set('{role}_token', token);")
        script_lines.append(f"    pm.collectionVariables.set('active_role', '{role}');")
    else:
        script_lines.append("    pm.collectionVariables.set('active_role', 'custom');")
    script_lines.append("}")
    script_lines.append("if (data.user && data.user.id) {")
    script_lines.append("    pm.collectionVariables.set('last_user_id', data.user.id.toString());")
    script_lines.append("}")
    return {
        "listen": "test",
        "script": {"type": "text/javascript", "exec": script_lines},
    }


def logout_test_script():
    script_lines = [
        "if (pm.response.code === 200) {",
        "    pm.collectionVariables.unset('auth_token');",
        "    pm.collectionVariables.set('active_role', '');",
        "}",
    ]
    return {
        "listen": "test",
        "script": {"type": "text/javascript", "exec": script_lines},
    }


def switch_token_event(role_key):
    exec_lines = [
        f"const storedToken = pm.collectionVariables.get('{role_key}_token');",
        "pm.test('Token available for role', function () {",
        "    pm.expect(storedToken, 'stored token').to.exist;",
        "});",
        f"pm.collectionVariables.set('auth_token', storedToken);",
        f"pm.collectionVariables.set('active_role', '{role_key}');",
    ]
    return {"listen": "prerequest", "script": {"type": "text/javascript", "exec": exec_lines}}


def create_request(
    name,
    method,
    path_segments,
    *,
    description=None,
    headers=None,
    body=None,
    tests=None,
    query=None,
):
    request_headers = []
    if headers:
        request_headers.extend(headers)
    elif method in {"POST", "PUT", "PATCH"} and (not body or body.get("mode") == "raw"):
        request_headers.append({"key": "Content-Type", "value": "application/json"})

    request = {
        "method": method,
        "header": request_headers,
        "url": build_url(path_segments, query=query),
    }

    if body:
        request["body"] = body

    item = {"name": name, "request": request}

    if description:
        item["description"] = description

    if tests:
        item["event"] = tests if isinstance(tests, list) else [tests]

    return item


def authentication_folder():
    items = [
        create_request(
            "Register",
            "POST",
            ["api", "register"],
            body=raw_body(
                {
                    "name": "Test User",
                    "email": "new.user@example.com",
                    "password": "Password123!",
                    "password_confirmation": "Password123!",
                }
            ),
        ),
        create_request(
            "Login (custom credentials)",
            "POST",
            ["api", "login"],
            body=raw_body(
                {
                    "email": "user@example.com",
                    "password": "password",
                }
            ),
            tests=[login_test_script()],
        ),
        create_request(
            "Login as Admin",
            "POST",
            ["api", "login"],
            body=raw_body(
                {
                    "email": "{{admin_email}}",
                    "password": "{{admin_password}}",
                }
            ),
            tests=[login_test_script("admin")],
        ),
        create_request(
            "Login as Agent",
            "POST",
            ["api", "login"],
            body=raw_body(
                {
                    "email": "{{agent_email}}",
                    "password": "{{agent_password}}",
                }
            ),
            tests=[login_test_script("agent")],
        ),
        create_request(
            "Login as Merchant",
            "POST",
            ["api", "login"],
            body=raw_body(
                {
                    "email": "{{merchant_email}}",
                    "password": "{{merchant_password}}",
                }
            ),
            tests=[login_test_script("merchant")],
        ),
        create_request(
            "Logout",
            "POST",
            ["api", "logout"],
            tests=[logout_test_script()],
        ),
        {
            "name": "Use stored Admin token",
            "request": {
                "method": "GET",
                "header": [],
                "url": build_url(["api", "me"]),
            },
            "event": [switch_token_event("admin")],
        },
        {
            "name": "Use stored Agent token",
            "request": {
                "method": "GET",
                "header": [],
                "url": build_url(["api", "me"]),
            },
            "event": [switch_token_event("agent")],
        },
        {
            "name": "Use stored Merchant token",
            "request": {
                "method": "GET",
                "header": [],
                "url": build_url(["api", "me"]),
            },
            "event": [switch_token_event("merchant")],
        },
        {
            "name": "Use manual token value",
            "request": {
                "method": "GET",
                "header": [],
                "url": build_url(["api", "me"]),
            },
            "event": [
                {
                    "listen": "prerequest",
                    "script": {
                        "type": "text/javascript",
                        "exec": [
                            "const manualToken = pm.collectionVariables.get('manual_token') || pm.environment.get('manual_token');",
                            "pm.test('Manual token available', function () {",
                            "    pm.expect(manualToken, 'manual token').to.exist;",
                            "});",
                            "pm.collectionVariables.set('auth_token', manualToken);",
                            "pm.collectionVariables.set('active_role', 'manual');",
                        ],
                    },
                }
            ],
        },
        create_request(
            "Me",
            "GET",
            ["api", "me"],
        ),
        create_request(
            "Forgot password",
            "POST",
            ["api", "forgot-password"],
            body=raw_body({"email": "user@example.com"}),
        ),
        create_request(
            "Reset password",
            "POST",
            ["api", "reset-password"],
            body=raw_body(
                {
                    "token": "{{password_reset_token}}",
                    "email": "user@example.com",
                    "password": "NewPassword123!",
                    "password_confirmation": "NewPassword123!",
                }
            ),
        ),
    ]
    return {"name": "Authentication", "item": items}


def email_verification_folder():
    items = [
        create_request(
            "Resend verification email",
            "POST",
            ["api", "email", "verification-notification"],
        ),
        create_request(
            "Verification status",
            "GET",
            ["api", "email", "verification-status"],
        ),
        create_request(
            "Verify via signed URL",
            "GET",
            ["api", "email", "verify", "{{user_id}}", "{{verification_hash}}"],
        ),
        create_request(
            "Frontend verify",
            "GET",
            ["api", "verify-email"],
            query=[{"key": "id", "value": "{{user_id}}"}, {"key": "hash", "value": "{{verification_hash}}"}],
        ),
    ]
    return {"name": "Email Verification", "item": items}


def public_catalog_folder():
    items = [
        create_request("List categories", "GET", ["api", "categories"]),
        create_request("Category tree", "GET", ["api", "categories", "tree"]),
        create_request(
            "Get category",
            "GET",
            ["api", "categories", "{{category_id}}"],
        ),
        create_request(
            "List products",
            "GET",
            ["api", "products"],
        ),
        create_request(
            "Featured products",
            "GET",
            ["api", "products", "featured"],
        ),
        create_request(
            "Get product",
            "GET",
            ["api", "products", "{{product_id}}"],
        ),
        create_request(
            "Low stock products",
            "GET",
            ["api", "products", "low-stock"],
        ),
    ]
    return {"name": "Public Catalog", "item": items}


def public_shipping_folder():
    items = [
        create_request(
            "Active shipping carriers",
            "GET",
            ["api", "shipping-carriers", "active"],
        ),
        create_request(
            "Calculate carrier cost (public)",
            "POST",
            ["api", "shipping-carriers", "{{shipping_carrier_id}}", "calculate-cost"],
            body=raw_body({"weight": 2.5, "service_type": "regular"}),
        ),
        create_request(
            "Calculate shipping cost (public)",
            "POST",
            ["api", "orders", "calculate-shipping-cost"],
            body=raw_body(
                {
                    "carrier_id": "{{shipping_carrier_id}}",
                    "service_type": "regular",
                    "weight": 2.5,
                }
            ),
        ),
        create_request(
            "Track shipment (public)",
            "GET",
            ["api", "shipments", "track", "{{tracking_number}}"],
        ),
        create_request(
            "Serve product image",
            "GET",
            ["api", "product-images", "{{image_path}}"],
            description="Replace {{image_path}} with the relative image path, e.g. products/sample.jpg",
        ),
    ]
    return {"name": "Public Shipping & Tools", "item": items}


def orders_folder():
    items = [
        create_request("List orders", "GET", ["api", "orders"]),
        create_request("Open orders", "GET", ["api", "orders", "open"]),
        create_request("Waiting for shipment", "GET", ["api", "orders", "waiting-shipment"]),
        create_request("Closed orders", "GET", ["api", "orders", "closed"]),
        create_request(
            "Create order",
            "POST",
            ["api", "orders"],
            body=raw_body(
                {
                    "merchant_id": "{{merchant_id}}",
                    "merchant_customer_id": "{{merchant_customer_id}}",
                    "shipping_type": "delivery",
                    "shipping_method": "regular",
                    "shipping_cost": 29.9,
                    "billing_address": SAMPLE_BILLING_ADDRESS,
                    "shipping_address": SAMPLE_SHIPPING_ADDRESS,
                    "items": [
                        {
                            "product_id": "{{product_id}}",
                            "quantity": 1,
                            "unit_price": 199.9,
                            "discount": 0,
                        },
                        {
                            "product_id": "{{secondary_product_id}}",
                            "quantity": 2,
                            "unit_price": 79.9,
                        }
                    ],
                    "notes": "הזמנת בדיקה מפוסטמן",
                }
            ),
        ),
        create_request(
            "Get order",
            "GET",
            ["api", "orders", "{{order_id}}"],
        ),
        create_request(
            "Update order",
            "PUT",
            ["api", "orders", "{{order_id}}"],
            body=raw_body(
                {
                    "status": "processing",
                    "payment_status": "pending",
                    "notes": "עדכון סטטוס הזמנה לבדיקה",
                    "shipping_address": SAMPLE_SHIPPING_ADDRESS,
                    "billing_address": SAMPLE_BILLING_ADDRESS,
                }
            ),
        ),
        create_request(
            "Delete order",
            "DELETE",
            ["api", "orders", "{{order_id}}"],
        ),
        create_request(
            "Orders by status",
            "GET",
            ["api", "orders", "status", "{{order_status}}"],
        ),
        create_request(
            "Dashboard stats",
            "GET",
            ["api", "orders", "dashboard", "stats"],
        ),
        create_request(
            "Sales performance",
            "GET",
            ["api", "orders", "dashboard", "sales-performance"],
            query=[{"key": "range", "value": "last_week"}],
        ),
        create_request(
            "Assign carrier to order",
            "POST",
            ["api", "orders", "{{order_id}}", "assign-carrier"],
            body=raw_body(
                {
                    "carrier_id": "{{shipping_carrier_id}}",
                    "service_type": "regular",
                }
            ),
        ),
        create_request(
            "Available carriers for order",
            "GET",
            ["api", "orders", "{{order_id}}", "available-carriers"],
        ),
        create_request(
            "Get order shipping settings",
            "GET",
            ["api", "orders", "{{order_id}}", "shipping-settings"],
        ),
        create_request(
            "Update order shipping settings",
            "PUT",
            ["api", "orders", "{{order_id}}", "shipping-settings"],
            body=raw_body(
                {
                    "delivery_window": "09:00-14:00",
                    "requires_signature": True,
                    "leave_at_door": False,
                    "pickup_point": None,
                    "comments": "נא להתקשר 30 דקות לפני הגעה",
                }
            ),
        ),
        create_request(
            "Calculate order shipping cost",
            "POST",
            ["api", "orders", "{{order_id}}", "calculate-shipping-cost"],
            body=raw_body(
                {
                    "carrier_id": "{{shipping_carrier_id}}",
                    "service_type": "regular",
                }
            ),
        ),
    ]
    return {"name": "Orders", "item": items}


def shipments_folder():
    items = [
        create_request("List shipments", "GET", ["api", "shipments"]),
        create_request(
            "Create shipment",
            "POST",
            ["api", "shipments"],
            body=raw_body(
                {
                    "order_id": "{{order_id}}",
                    "carrier_id": "{{shipping_carrier_id}}",
                    "tracking_number": "TRACK12345",
                    "status": "pending",
                    "weight": 2.6,
                    "length": 30,
                    "width": 20,
                    "height": 15,
                    "notes": "משלוח בדיקה - חולצות בייבי",
                }
            ),
        ),
        create_request("Get shipment", "GET", ["api", "shipments", "{{shipment_id}}"]),
        create_request(
            "Update shipment",
            "PUT",
            ["api", "shipments", "{{shipment_id}}"],
            body=raw_body({"status": "shipped"}),
        ),
        create_request("Delete shipment", "DELETE", ["api", "shipments", "{{shipment_id}}"]),
        create_request(
            "Shipments by status",
            "GET",
            ["api", "shipments", "status", "{{shipment_status}}"],
        ),
        create_request(
            "Add tracking event",
            "POST",
            ["api", "shipments", "{{shipment_id}}", "tracking-events"],
            body=raw_body(
                {
                    "status": "in_transit",
                    "location": "מרכז לוגיסטי, תל אביב",
                    "details": "המשלוח מוין ונשלח להפצה",
                    "occurred_at": "2024-11-01T10:30:00+02:00",
                }
            ),
        ),
    ]
    return {"name": "Shipments", "item": items}


def merchant_folder():
    items = [
        create_request("Merchant profile", "GET", ["api", "merchant", "profile"]),
        create_request(
            "Update merchant profile",
            "PUT",
            ["api", "merchant", "profile"],
            body=raw_body(
                {
                    "business_name": "Merchant LTD",
                    "business_id": "123456789",
                    "phone": "+97251000000",
                    "website": "https://merchant.example.com",
                    "description": "חנות לדוגמה לשליחת בדיקות",
                    "address": SAMPLE_ADDRESS,
                    "shipping_address": SAMPLE_SHIPPING_ADDRESS,
                    "bank_details": SAMPLE_BANK_DETAILS,
                }
            ),
        ),
        create_request(
            "Merchant dashboard",
            "GET",
            ["api", "merchant", "dashboard"],
        ),
        create_request(
            "Merchant customers",
            "GET",
            ["api", "merchant", "customers"],
        ),
        create_request(
            "Get merchant customer",
            "GET",
            ["api", "merchant", "customers", "{{customer_id}}"],
        ),
        create_request(
            "Update merchant customer",
            "PUT",
            ["api", "merchant", "customers", "{{customer_id}}"],
            body=raw_body(
                {
                    "name": "Updated Customer",
                    "phone": "+972520000000",
                    "notes": "מעדיף מסירה בבוקר",
                    "address": SAMPLE_SHIPPING_ADDRESS,
                }
            ),
        ),
        create_request(
            "Plugin order (merchant portal)",
            "POST",
            ["api", "plugin", "orders"],
            body=raw_body(
                {
                    "site_url": "{{plugin_site_url}}",
                    "order_number": "PLUGIN-{{plugin_order_number}}",
                    "total": 399.7,
                    "items": [
                        {"product_id": "{{product_id}}", "quantity": 1, "price": 199.9},
                        {"product_id": "{{secondary_product_id}}", "quantity": 2, "price": 99.9},
                    ],
                    "customer": {
                        "name": "נועם הלקוח",
                        "email": SAMPLE_EMAIL,
                        "phone": SAMPLE_PHONE,
                        "address": PLUGIN_CUSTOMER_ADDRESS,
                        "notes": "לקוח חוזר מהחנות המקוונת",
                    },
                    "source": "shopify",
                    "status": "paid",
                    "totals": {
                        "subtotal": 399.7,
                        "tax": 0,
                        "shipping_cost": 0,
                        "discount": 0,
                        "total": 399.7,
                    },
                    "shipping": {
                        "type": "delivery",
                        "method": "standard",
                        "cost": 0,
                    },
                }
            ),
        ),
    ]
    return {"name": "Merchant", "item": items}


def admin_dashboard_items():
    return [
        create_request("Admin dashboard ping", "GET", ["api", "admin", "dashboard"]),
        create_request("Dashboard alerts", "GET", ["api", "admin", "dashboard", "alerts"]),
        create_request("Orders summary", "GET", ["api", "admin", "orders", "summary"]),
    ]


def admin_users_items():
    return [
        create_request("List users", "GET", ["api", "users"]),
        create_request(
            "Create user",
            "POST",
            ["api", "users"],
            body=raw_body(
                {
                    "name": "New User",
                    "email": "new.user@example.com",
                    "password": "Password123!",
                    "role": "merchant",
                }
            ),
        ),
        create_request("Get user", "GET", ["api", "users", "{{user_id}}"]),
        create_request(
            "Update user",
            "PUT",
            ["api", "users", "{{user_id}}"],
            body=raw_body(
                {
                    "name": "Updated Name",
                    "phone": "+972530000000",
                    "notes": "עודכן מפוסטמן לבדיקה",
                }
            ),
        ),
        create_request("Delete user", "DELETE", ["api", "users", "{{user_id}}"]),
    ]


def admin_categories_items():
    return [
        create_request(
            "Create category",
            "POST",
            ["api", "categories"],
            body=raw_body(
                {
                    "name": "New Category",
                    "description": "קטגוריה לדוגמה לבדיקה",
                    "is_active": True,
                }
            ),
        ),
        create_request(
            "Update category",
            "PUT",
            ["api", "categories", "{{category_id}}"],
            body=raw_body(
                {
                    "name": "Updated Category",
                    "description": "עדכון קטגוריה מפוסטמן",
                    "is_active": True,
                }
            ),
        ),
        create_request("Delete category", "DELETE", ["api", "categories", "{{category_id}}"]),
    ]


def admin_products_items():
    return [
        create_request(
            "Create product",
            "POST",
            ["api", "products"],
            body=raw_body(
                {
                    "name": "New Product",
                    "sku": "SKU-001",
                    "price": 199.9,
                    "stock_quantity": 10,
                    "category_id": "{{category_id}}",
                    "description": "מוצר חדש לבדיקה",
                    "status": "active",
                    "weight": 1.5,
                    "tax_rate": 0.17,
                }
            ),
        ),
        create_request(
            "Update product",
            "PUT",
            ["api", "products", "{{product_id}}"],
            body=raw_body(
                {
                    "name": "Updated Product",
                    "price": 149.9,
                    "stock_quantity": 8,
                    "status": "draft",
                    "weight": 1.35,
                }
            ),
        ),
        create_request("Delete product", "DELETE", ["api", "products", "{{product_id}}"]),
        create_request(
            "Upload product image",
            "POST",
            ["api", "product-images"],
            headers=[{"key": "Content-Type", "value": "multipart/form-data"}],
            body=form_data_body(
                [
                    {
                        "key": "image",
                        "type": "file",
                        "src": ["/absolute/path/to/image.jpg"],
                    }
                ]
            ),
            description="Update the file path before sending.",
        ),
    ]


def admin_merchants_items():
    return [
        create_request("List merchants", "GET", ["api", "merchants"]),
        create_request(
            "Create merchant",
            "POST",
            ["api", "merchants"],
            body=raw_body(
                {
                    "business_name": "Merchant Ltd",
                    "business_id": "987654321",
                    "phone": "+972540000000",
                    "status": "active",
                    "address": SAMPLE_ADDRESS,
                    "shipping_address": SAMPLE_SHIPPING_ADDRESS,
                }
            ),
        ),
        create_request("Get merchant", "GET", ["api", "merchants", "{{merchant_id}}"]),
        create_request(
            "Update merchant",
            "PUT",
            ["api", "merchants", "{{merchant_id}}"],
            body=raw_body({"status": "suspended"}),
        ),
        create_request("Delete merchant", "DELETE", ["api", "merchants", "{{merchant_id}}"]),
    ]


def admin_shipping_items():
    return [
        create_request("List carriers", "GET", ["api", "shipping-carriers"]),
        create_request(
            "Create carrier",
            "POST",
            ["api", "shipping-carriers"],
            body=raw_body(
                {
                    "name": "Speedy Express",
                    "code": "SPEEDY",
                    "description": "חברת משלוחים מהירה לבדיקה",
                    "api_url": "https://carrier.example.com/api",
                    "api_key": "test-key",
                    "api_secret": "test-secret",
                    "base_rate": 25,
                    "rate_per_kg": 9.9,
                    "service_types": ["regular", "express"],
                    "is_active": True,
                    "is_test_mode": True,
                }
            ),
        ),
        create_request("Get carrier", "GET", ["api", "shipping-carriers", "{{shipping_carrier_id}}"]),
        create_request(
            "Update carrier",
            "PUT",
            ["api", "shipping-carriers", "{{shipping_carrier_id}}"],
            body=raw_body({"is_active": False}),
        ),
        create_request("Delete carrier", "DELETE", ["api", "shipping-carriers", "{{shipping_carrier_id}}"]),
        create_request("Carrier stats", "GET", ["api", "shipping-carriers", "stats"]),
        create_request(
            "Test carrier connection",
            "POST",
            ["api", "shipping-carriers", "{{shipping_carrier_id}}", "test-connection"],
        ),
        create_request(
            "Admin calculate carrier cost",
            "POST",
            ["api", "shipping-carriers", "{{shipping_carrier_id}}", "calculate-cost"],
            body=raw_body({"weight": 3.2, "service_type": "express"}),
        ),
        create_request(
            "Get merchant shipping settings",
            "GET",
            ["api", "merchant", "shipping-settings"],
        ),
        create_request(
            "Update merchant shipping settings",
            "PUT",
            ["api", "merchant", "shipping-settings"],
            body=raw_body(
                {
                    "default_carrier_id": "{{shipping_carrier_id}}",
                    "allow_express": True,
                    "allow_pickup": False,
                    "handling_time_hours": 24,
                    "default_service_type": "regular",
                }
            ),
        ),
    ]


def admin_plugin_sites_items():
    return [
        create_request("List plugin sites", "GET", ["api", "plugin-sites"]),
        create_request(
            "Create plugin site",
            "POST",
            ["api", "plugin-sites"],
            body=raw_body(
                {
                    "name": "Shopify Store",
                    "site_url": "https://shop.example.com",
                    "platform": "shopify",
                    "status": "active",
                    "merchant_id": "{{merchant_id}}",
                    "api_key": "shopify-api-key",
                    "api_secret": "shopify-secret",
                }
            ),
        ),
    ]


def build_collection():
    collection = {
        "info": {
            "_postman_id": "kfitz-api-collection",
            "name": "KFitz API Collection",
            "description": "Up-to-date API collection for KFitz Backend including role-based helpers and latest endpoints.",
            "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
        },
        "auth": {
            "type": "bearer",
            "bearer": [
                {
                    "key": "token",
                    "value": "{{auth_token}}",
                    "type": "string",
                }
            ],
        },
        "item": [
            authentication_folder(),
            email_verification_folder(),
            public_catalog_folder(),
            public_shipping_folder(),
            orders_folder(),
            shipments_folder(),
            merchant_folder(),
            {
                "name": "Admin",
                "item": [
                    {"name": "Dashboard", "item": admin_dashboard_items()},
                    {"name": "Users", "item": admin_users_items()},
                    {"name": "Categories", "item": admin_categories_items()},
                    {"name": "Products", "item": admin_products_items()},
                    {"name": "Merchants", "item": admin_merchants_items()},
                    {"name": "Shipping", "item": admin_shipping_items()},
                    {"name": "Plugin Sites", "item": admin_plugin_sites_items()},
                ],
            },
        ],
        "variable": [
            {"key": "base_url", "value": "http://localhost:8000", "type": "string"},
            {"key": "auth_token", "value": "", "type": "string"},
            {"key": "active_role", "value": "", "type": "string"},
            {"key": "admin_token", "value": "", "type": "string"},
            {"key": "agent_token", "value": "", "type": "string"},
            {"key": "merchant_token", "value": "", "type": "string"},
            {"key": "manual_token", "value": "", "type": "string"},
            {"key": "admin_email", "value": "admin@example.com", "type": "string"},
            {"key": "admin_password", "value": "password", "type": "string"},
            {"key": "agent_email", "value": "agent@example.com", "type": "string"},
            {"key": "agent_password", "value": "password", "type": "string"},
            {"key": "merchant_email", "value": "merchant@example.com", "type": "string"},
            {"key": "merchant_password", "value": "password", "type": "string"},
            {"key": "password_reset_token", "value": "", "type": "string"},
            {"key": "user_id", "value": "1", "type": "string"},
            {"key": "category_id", "value": "1", "type": "string"},
            {"key": "product_id", "value": "1", "type": "string"},
            {"key": "secondary_product_id", "value": "2", "type": "string"},
            {"key": "order_id", "value": "1", "type": "string"},
            {"key": "order_status", "value": "pending", "type": "string"},
            {"key": "shipment_id", "value": "1", "type": "string"},
            {"key": "shipment_status", "value": "pending", "type": "string"},
            {"key": "customer_id", "value": "1", "type": "string"},
            {"key": "merchant_id", "value": "1", "type": "string"},
            {"key": "merchant_customer_id", "value": "1", "type": "string"},
            {"key": "shipping_carrier_id", "value": "1", "type": "string"},
            {"key": "plugin_site_id", "value": "1", "type": "string"},
            {"key": "plugin_site_url", "value": "https://merchant.example.com", "type": "string"},
            {"key": "plugin_order_number", "value": "TEST-1001", "type": "string"},
            {"key": "tracking_number", "value": "TRACK12345", "type": "string"},
            {"key": "verification_hash", "value": "hash", "type": "string"},
            {"key": "image_path", "value": "products/sample.jpg", "type": "string"},
            {"key": "last_user_id", "value": "", "type": "string"},
        ],
    }
    return collection


def main():
    collection = build_collection()
    target_path = Path(__file__).resolve().parent.parent / "KFitz_API_Collection.json"
    with target_path.open("w", encoding="utf-8") as f:
        json.dump(collection, f, indent=2)
        f.write("\n")


if __name__ == "__main__":
    main()
