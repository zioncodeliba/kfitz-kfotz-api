# API Documentation

## Base URL
```
http://localhost:8000/api
```

## Authentication
המערכת משתמשת ב-Laravel Sanctum לאותנטיקציה. יש לכלול את ה-token ב-header:
```
Authorization: Bearer {token}
```

## Endpoints

### Authentication

#### Register
```http
POST /register
```

**Body:**
```json
{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "Password123",
    "password_confirmation": "Password123"
}
```

**Response:**
```json
{
    "success": true,
    "message": "User registered successfully",
    "data": {
        "token": "1|abc123...",
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com",
            "roles": ["user"]
        }
    }
}
```

#### Login
```http
POST /login
```

**Body:**
```json
{
    "email": "john@example.com",
    "password": "Password123"
}
```

#### Logout
```http
POST /logout
```
*Requires authentication*

#### Get Current User
```http
GET /me
```
*Requires authentication*

#### Forgot Password
```http
POST /forgot-password
```

**Body:**
```json
{
    "email": "john@example.com"
}
```

#### Reset Password
```http
POST /reset-password
```

**Body:**
```json
{
    "token": "reset_token",
    "email": "john@example.com",
    "password": "NewPassword123",
    "password_confirmation": "NewPassword123"
}
```

### User Management (Admin Only)

#### Get All Users
```http
GET /users
```
*Requires admin role*

#### Create User
```http
POST /users
```
*Requires admin role*

**Body:**
```json
{
    "name": "Jane Doe",
    "email": "jane@example.com",
    "password": "Password123",
    "password_confirmation": "Password123"
}
```

#### Get User
```http
GET /users/{id}
```
*Requires admin role*

#### Update User
```http
PUT /users/{id}
```
*Requires admin role*

**Body:**
```json
{
    "name": "Jane Smith",
    "email": "jane.smith@example.com"
}
```

#### Delete User
```http
DELETE /users/{id}
```
*Requires admin role*

#### Assign Role to User
```http
POST /users/{userId}/assign-role
```
*Requires admin role*

**Body:**
```json
{
    "role": "seller"
}
```

#### Remove Role from User
```http
POST /users/{userId}/remove-role
```
*Requires admin role*

**Body:**
```json
{
    "role": "seller"
}
```

### Role Management (Admin Only)

#### Get All Roles
```http
GET /roles
```
*Requires admin role*

#### Create Role
```http
POST /roles
```
*Requires admin role*

**Body:**
```json
{
    "name": "moderator"
}
```

#### Get Role
```http
GET /roles/{id}
```
*Requires admin role*

#### Update Role
```http
PUT /roles/{id}
```
*Requires admin role*

**Body:**
```json
{
    "name": "super_moderator"
}
```

#### Delete Role
```http
DELETE /roles/{id}
```
*Requires admin role*

### Shipping Carriers

#### Get All Carriers
```http
GET /shipping-carriers
```
*Requires admin role*

#### Get Active Carriers (Public)
```http
GET /shipping-carriers/active
```

#### Get Carrier Statistics
```http
GET /shipping-carriers/stats
```
*Requires admin role*

#### Get Single Carrier
```http
GET /shipping-carriers/{id}
```
*Requires admin role*

#### Create Carrier
```http
POST /shipping-carriers
```
*Requires admin role*

**Body:**
```json
{
    "name": "FedEx",
    "code": "fedex",
    "description": "Federal Express shipping services",
    "api_url": "https://api.fedex.com/v1",
    "api_key": "your_api_key",
    "api_secret": "your_api_secret",
    "service_types": ["regular", "express", "overnight"],
    "package_types": ["envelope", "box", "pallet"],
    "base_rate": 15.00,
    "rate_per_kg": 2.50,
    "is_active": true,
    "is_test_mode": true
}
```

#### Update Carrier
```http
PUT /shipping-carriers/{id}
```
*Requires admin role*

**Body:**
```json
{
    "name": "Updated FedEx",
    "description": "Updated description",
    "base_rate": 16.00
}
```

#### Delete Carrier
```http
DELETE /shipping-carriers/{id}
```
*Requires admin role*

#### Test Carrier Connection
```http
POST /shipping-carriers/{id}/test-connection
```
*Requires admin role*

#### Calculate Shipping Cost (Public)
```http
POST /shipping-carriers/{id}/calculate-cost
```

**Body:**
```json
{
    "weight": 5.5,
    "service_type": "express"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Shipping cost calculated successfully",
    "data": {
        "carrier": "FedEx",
        "weight": 5.5,
        "service_type": "express",
        "cost": 43.125,
        "currency": "USD"
    }
}
```

### Order Shipping Management

#### Get Available Carriers for Order
```http
GET /orders/{id}/available-carriers
```
*Requires authentication*

**Response:**
```json
{
    "success": true,
    "message": "Success",
    "data": {
        "order_id": 1,
        "order_weight": 0,
        "available_carriers": [
            {
                "id": 1,
                "name": "FedEx",
                "code": "fedex",
                "description": "Federal Express shipping services",
                "service_types": ["regular", "express", "overnight"],
                "package_types": ["envelope", "box", "pallet"],
                "base_rate": "15.00",
                "rate_per_kg": "2.50",
                "costs": {
                    "regular": 15,
                    "express": 22.5,
                    "overnight": 15
                }
            }
        ]
    }
}
```

#### Assign Carrier to Order
```http
POST /orders/{id}/assign-carrier
```
*Requires authentication*

**Body:**
```json
{
    "carrier_id": 1,
    "service_type": "express"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Carrier assigned successfully",
    "data": {
        "order": {
            "id": 1,
            "carrier_id": 1,
            "carrier_service_type": "express",
            "shipping_company": "FedEx",
            "shipping_cost": 22.50,
            "total": 9382.48,
            "carrier": {
                "id": 1,
                "name": "FedEx",
                "code": "fedex"
            }
        },
        "carrier_info": {
            "id": 1,
            "name": "FedEx",
            "code": "fedex",
            "service_type": "express",
            "cost": 22.5
        },
        "shipping_cost": 22.5
    }
}
```

#### Calculate Shipping Cost (Public)
```http
POST /orders/calculate-shipping-cost
```

**Body:**
```json
{
    "carrier_id": 1,
    "service_type": "express",
    "weight": 5.5
}
```

**Response:**
```json
{
    "success": true,
    "message": "Success",
    "data": {
        "carrier": {
            "id": 1,
            "name": "FedEx",
            "code": "fedex"
        },
        "service_type": "express",
        "weight": 5.5,
        "shipping_cost": 43.125
    }
}
```

## Response Format

כל התגובות עוקבות אחרי הפורמט הבא:

### Success Response
```json
{
    "success": true,
    "message": "Operation completed successfully",
    "data": {
        // הנתונים
    }
}
```

### Error Response
```json
{
    "success": false,
    "message": "Error description"
}
```

### Validation Error Response
```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "field": ["Error message"]
    }
}
```

## Status Codes

- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `405` - Method Not Allowed
- `422` - Validation Error
- `500` - Internal Server Error

## Roles

המערכת כוללת את התפקידים הבאים:
- `admin` - גישה מלאה לכל הפונקציות
- `user` - משתמש רגיל
- `seller` - מוכר
- `viewer` - צופה בלבד

## Security Features

1. **Password Requirements**: סיסמה חייבת להכיל לפחות 8 תווים, אות גדולה, אות קטנה ומספר
2. **Role Protection**: לא ניתן למחוק תפקידים בסיסיים (admin, user)
3. **Admin Protection**: לא ניתן למחוק את המשתמש האחרון עם תפקיד admin
4. **Role Assignment**: לא ניתן להסיר את התפקיד האחרון של משתמש
5. **Token-based Authentication**: שימוש ב-Laravel Sanctum

## Testing

### Default Admin User
```
Email: admin@example.com
Password: password123
```

### Running Tests
```bash
php artisan test
```

### Seeding Database
```bash
php artisan db:seed
``` 