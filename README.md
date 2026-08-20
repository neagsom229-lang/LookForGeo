# TraceGeo - AI-Powered OSINT Image Geolocation

![TraceGeo Logo](public/images/logo.png)

## 🚀 Features

- **AI-Powered Geolocation**: Uses Gemini AI for intelligent image analysis
- **Real-Time Analysis**: Instant location identification from images
- **User Authentication**: Secure login and registration
- **Landmark Database**: 50+ landmarks with exact coordinates
- **Confidence Scoring**: Multi-factor confidence calculation
- **Street View Integration**: Google Maps Street View
- **OSINT Report Export**: Professional report generation
- **Responsive Design**: Works on all devices

## 📦 Tech Stack

- **Backend**: Laravel 12.x
- **Frontend**: HTML, CSS, JavaScript, FontAwesome
- **AI**: Google Gemini API
- **Database**: SQLite / MySQL
- **Maps**: Leaflet.js, OpenStreetMap
- **Authentication**: Laravel Sanctum

## 🛠️ Installation

### Prerequisites

- PHP 8.2+
- Composer
- Node.js (optional)
- MySQL or SQLite

### Setup

```bash
# Clone repository
git clone https://github.com/yourusername/tracegeo.git
cd tracegeo

# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate app key
php artisan key:generate

# Configure database in .env
# DB_CONNECTION=sqlite
# or use MySQL

# Run migrations
php artisan migrate

# Seed landmarks
php artisan db:seed --class=LandmarkSeeder

# Start server
php artisan serve