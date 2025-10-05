# 🚀 KFitz Backend API

Laravel API עם מערכת אותנטיקציה מלאה, ניהול תפקידים, ואימות אימייל.

## ✨ **תכונות עיקריות**

- 🔐 **אותנטיקציה מלאה** - Laravel Sanctum
- 👥 **ניהול משתמשים ותפקידים** - RBAC System
- 📧 **אימות אימייל** - Email Verification
- 🔒 **אבטחה מתקדמת** - Role-based Access Control
- 📚 **API מלא** - 22 endpoints
- 🧪 **בדיקות** - Unit & Feature Tests

## 🛠 **טכנולוגיות**

- **Laravel 11** - PHP Framework
- **Laravel Sanctum** - API Authentication
- **MySQL/PostgreSQL** - Database
- **Laravel Mail** - Email System
- **PHPUnit** - Testing

## 📦 **התקנה**

### **דרישות מקדימות**
- PHP 8.2+
- Composer
- MySQL/PostgreSQL
- Node.js (לפיתוח)

### **התקנה מהירה**

```bash
# 1. Clone the repository
git clone <repository-url>
cd kfitz-backend

# 2. Install dependencies
composer install

# 3. Copy environment file
cp .env.example .env

# 4. Generate application key
php artisan key:generate

# 5. Configure database in .env file
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kfitz_backend
DB_USERNAME=root
DB_PASSWORD=

# 6. Run migrations
php artisan migrate

# 7. Seed database
php artisan db:seed

# 8. Start development server
php artisan serve
```

### **הגדרות נוספות**

```env
# Email Configuration
MAIL_MAILER=log
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="KFitz App"

# Frontend URL for email verification
VERIFICATION_URL=http://localhost:3000

# App URL
APP_URL=http://localhost:8000
```

## 🚀 **שימוש מהיר**

### **התחברות כמנהל**
```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "password123"
  }'
```

### **בדיקת API**
```bash
# Get current user
curl -X GET http://localhost:8000/api/me \
  -H "Authorization: Bearer YOUR_TOKEN"

# Admin dashboard
curl -X GET http://localhost:8000/api/admin/dashboard \
  -H "Authorization: Bearer ADMIN_TOKEN"
```

## 📚 **API Documentation**

### **Base URL**
```
http://localhost:8000/api
```

### **Endpoints Overview**

| Category | Endpoints | Description |
|----------|-----------|-------------|
| **Authentication** | 6 | הרשמה, התחברות, התנתקות, שחזור סיסמה |
| **Email Verification** | 3 | אימות אימייל, סטטוס, שליחה חוזרת |
| **User Management** | 7 | CRUD למשתמשים (Admin only) |
| **Role Management** | 5 | CRUD לתפקידים (Admin only) |
| **Admin Dashboard** | 1 | לוח בקרה למנהלים |

### **תפקידים (Roles)**
- `admin` - גישה מלאה לכל הפונקציות
- `user` - משתמש רגיל
- `seller` - מוכר
- `viewer` - צופה בלבד

## 🧪 **בדיקות**

### **הרצת בדיקות**
```bash
# Run all tests
php artisan test

# Run specific test
php artisan test --filter=AuthTest

# Run with coverage
php artisan test --coverage
```

### **בדיקות ידניות**
1. **Postman Collection** - `KFitz_API_Collection.json`
2. **API Documentation** - `API_DOCUMENTATION.md`
3. **Project Status** - `PROJECT_STATUS.md`

## 📁 **מבנה הפרויקט**

```
app/
├── Http/
│   ├── Controllers/Api/
│   │   ├── AuthController.php          # אותנטיקציה
│   │   ├── UserController.php          # ניהול משתמשים
│   │   ├── RoleController.php          # ניהול תפקידים
│   │   └── EmailVerificationController.php # אימות אימייל
│   ├── Middleware/
│   │   ├── CheckUserRole.php           # בדיקת תפקידים
│   │   └── EnsureEmailIsVerified.php   # אימות אימייל
│   └── Resources/
│       └── UserResource.php            # API Resource
├── Models/
│   ├── User.php                        # מודל משתמש
│   └── Role.php                        # מודל תפקיד
├── Notifications/
│   └── VerifyEmailNotification.php     # הודעת אימות אימייל
└── Traits/
    └── ApiResponse.php                 # תגובות API אחידות

routes/
└── api.php                            # כל ה-API routes

config/
├── auth.php                           # הגדרות אותנטיקציה
├── mail.php                           # הגדרות מייל
└── app.php                            # הגדרות אפליקציה

database/
├── migrations/                        # מיגרציות DB
└── seeders/
    └── DatabaseSeeder.php             # זריעת נתונים
```

## 🔧 **הגדרות מתקדמות**

### **Email Configuration**
```env
# For production - SMTP
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls

# For production - Postmark
MAIL_MAILER=postmark
POSTMARK_TOKEN=your-postmark-token

# For production - Resend
MAIL_MAILER=resend
RESEND_API_KEY=your-resend-api-key
```

### **Frontend Integration**
```env
# Set your frontend URL for email verification
VERIFICATION_URL=https://your-frontend.com
```

## 🚀 **Deployment**

### **Production Checklist**
- [ ] Configure production database
- [ ] Set up email service (SMTP/Postmark/Resend)
- [ ] Configure APP_URL and VERIFICATION_URL
- [ ] Set APP_ENV=production
- [ ] Configure caching (Redis/Memcached)
- [ ] Set up SSL certificate
- [ ] Configure web server (Nginx/Apache)

### **Environment Variables**
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-api.com
VERIFICATION_URL=https://your-frontend.com

DB_CONNECTION=mysql
DB_HOST=your-db-host
DB_DATABASE=your-db-name
DB_USERNAME=your-db-user
DB_PASSWORD=your-db-password

MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_USERNAME=your-email
MAIL_PASSWORD=your-password
```

## 🤝 **תרומה**

1. Fork the repository
2. Create feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Open Pull Request

## 📄 **רישיון**

פרויקט זה מוגן תחת רישיון MIT.

## 📞 **תמיכה**

- **Documentation**: `API_DOCUMENTATION.md`
- **Project Status**: `PROJECT_STATUS.md`
- **Postman Collection**: `KFitz_API_Collection.json`

---

**נכתב ב**: 2025-01-XX  
**גרסה**: 1.0.0  
**סטטוס**: ✅ מוכן לשימוש
