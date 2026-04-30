#!/bin/bash

# WK Fast Search System - Cron Setup Script
# This script sets up the Laravel scheduler to run automatically

echo "Setting up WK Fast Search System cron jobs..."

# Get the current directory (where the Laravel app is located)
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Find PHP path
PHP_PATH=$(which php)
if [ -z "$PHP_PATH" ]; then
    echo "Error: PHP not found in PATH. Please install PHP or update PATH."
    exit 1
fi

# Create the cron entry with full paths and proper environment
CRON_ENTRY="* * * * * cd $APP_DIR && $PHP_PATH artisan schedule:run >> $APP_DIR/storage/logs/cron.log 2>&1"

# Check if the cron entry already exists
if crontab -l 2>/dev/null | grep -q "schedule:run"; then
    echo "Cron entry already exists. Skipping..."
else
    # Add the cron entry
    (crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -
    echo "Cron entry added successfully!"
fi

echo ""
echo "Cron setup complete! The following tasks are now scheduled:"
echo "• Feed sync: Every 12 hours (00:00 and 12:00) - syncs products from products.json"
echo "• Popular searches sync: Every hour - syncs popular searches from JSON"
echo ""
echo "To verify the setup, run: php artisan schedule:list"
echo "To test manually, run: php artisan schedule:run"
echo "To check cron logs, run: tail -f storage/logs/cron.log"
echo "To test popular searches sync directly, run: php artisan popular-searches:sync"
