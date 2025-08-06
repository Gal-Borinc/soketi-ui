#!/bin/bash
set -e

echo "🚀 Initializing Upload Metrics System..."

# Run database migrations
php artisan migrate --force

# Start cron service
service cron start

echo "✅ Upload Metrics System initialized!"