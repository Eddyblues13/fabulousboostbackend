# Fabulous Boost Backend - Project Setup

This is a cloned project from Boostelixbackend with the new name "Fabulous Boost Backend".

## Quick Start

1. **Install Dependencies**
   ```bash
   composer install
   ```

2. **Environment Setup**
   - Copy `.env.example` to `.env`
   - Update database configuration
   - Generate application key: `php artisan key:generate`

3. **Database Setup**
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

4. **Run Development Server**
   ```bash
   php artisan serve
   ```

## Important Notes

- This project is independent from Boostelixbackend
- Update all environment variables for your new setup
- Update CORS settings if using a different frontend URL
- Update any hardcoded URLs or references to Boostelix

## Cron Jobs

See `CRON_JOBS_SETUP_GUIDE.md` for setting up scheduled tasks.

