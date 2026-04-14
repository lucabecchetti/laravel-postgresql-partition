# Testing the Laravel PostgreSQL Partition Package

This guide explains how to test the `laravel-pgsql-partition` package using the included test script.

## 📋 Prerequisites

- PHP >= 7.1
- Composer
- Docker and Docker Compose
- PHP extension `pdo_pgsql` enabled

Verify the PostgreSQL extension:
```bash
php -m | grep pdo_pgsql
```

## 🚀 Setup and Testing

### 1. Start PostgreSQL with Docker

From the package directory, start the PostgreSQL container:
```bash
docker-compose up -d
```

Verify the container is running:
```bash
docker ps | grep postgres
```

Wait a few seconds for PostgreSQL to start completely (about 10-15 seconds).

You can verify PostgreSQL is ready:
```bash
docker-compose exec postgres pg_isready -U postgres
```

### 2. Install Dependencies

Install all required dependencies:
```bash
composer install
```

### 3. Run the Test Script

Run the main test script:
```bash
php test-partition.php
```

## 📝 What the Test Script Does

The `test-partition.php` script automatically runs:

1. **Connection test** - Verifies the connection to the PostgreSQL database
2. **Partitioned table creation** - Creates an `orders` table partitioned by RANGE
3. **Partition creation** - Creates partitions for years 2022-2025
4. **Partition listing** - Lists all created partitions
5. **Data insertion** - Inserts test data into each partition
6. **Data queries** - Runs queries to verify the inserted data
7. **Partition deletion** - Tests dropping a partition

### Expected Output

If everything works correctly, you will see output similar to:

```
=== Test Laravel PostgreSQL Partition Package ===

1. Database connection test...
   ✓ Connected to: PostgreSQL 15.x...

2. Creating partitioned table 'orders'...
   ✓ Table 'orders' created

3. Adding partitions for years 2022-2025...
   ✓ Partitions created

4. List of created partitions...
   - year2022
   - year2023
   - year2024
   - year2025
   - orders_default

5. Inserting test data...
   ✓ Inserted order for 2022-06-15
   ✓ Inserted order for 2023-06-15
   ✓ Inserted order for 2024-06-15
   ✓ Inserted order for 2025-06-15

6. Querying inserted data...
   ✓ Total orders: 4
   - ID: 1, Date: 2022-06-15, Total: 450.00
   - ID: 2, Date: 2023-06-15, Total: 320.00
   ...

7. Testing partition deletion 'year2022'...
   ✓ Partition deleted

8. Remaining partitions...
   - year2023
   - year2024
   - year2025
   - orders_default

=== All tests completed successfully! ===
```

## ⚙️ Configuration

### Environment Variables

You can customize the database connection using environment variables:

```bash
export DB_HOST=127.0.0.1
export DB_PORT=5432
export DB_DATABASE=partition_test
export DB_USERNAME=postgres
export DB_PASSWORD=postgres

php test-partition.php
```

### Docker Configuration

The `docker-compose.yml` file configures:
- **Image**: PostgreSQL 15
- **Port**: 5432
- **Database**: `partition_test`
- **User**: `postgres`
- **Password**: `postgres`

To change these settings, edit `docker-compose.yml`.

## 🔧 Troubleshooting

### Error: "Connection refused"

**Problem**: PostgreSQL is not started yet or the port is in use.

**Solution**:
```bash
# Verify the container is running
docker ps

# Check logs for errors
docker-compose logs postgres

# Restart the container
docker-compose restart

# If port 5432 is in use, stop other PostgreSQL containers
docker ps | grep postgres
docker stop <container-id>
```

### Error: "extension pdo_pgsql not found"

**Problem**: The PHP extension for PostgreSQL is not installed.

**Solution** (macOS with Homebrew):
```bash
# Install PHP with PostgreSQL extension
brew install php@8.1
pecl install pdo_pgsql

# Enable the extension in php.ini
echo "extension=pdo_pgsql.so" >> $(php --ini | grep "Loaded Configuration" | sed -e "s|.*:\s*||")
```

**Solution** (Ubuntu/Debian):
```bash
sudo apt-get update
sudo apt-get install php-pgsql
sudo systemctl restart php-fpm  # or apache2, depending on your setup
```

**Solution** (verify installation):
```bash
php -m | grep -i pdo_pgsql
# Should output: pdo_pgsql
```

### Error: "Partitioning requires PostgreSQL 10 or higher"

**Problem**: The PostgreSQL version is too old.

**Solution**: The `docker-compose.yml` uses PostgreSQL 15, which supports partitioning. Verify the version:
```bash
# Find the container name
docker ps | grep postgres

# Check version (replace <container-name> with the actual name)
docker exec <container-name> psql -U postgres -c "SELECT version();"

# Or use docker-compose
docker-compose exec postgres psql -U postgres -c "SELECT version();"
```

### Error during composer install

**Problem**: Missing dependencies or conflicts.

**Solution**:
```bash
# Clear Composer cache
composer clear-cache

# Reinstall dependencies
rm -rf vendor composer.lock
composer install

# If problems persist, verify PHP version
php -v  # Must be >= 7.1
```

### Error: "Class 'Illuminate\Database\Capsule\Manager' not found"

**Problem**: Illuminate dependencies are not installed correctly.

**Solution**:
```bash
# Verify illuminate/database is installed
composer show illuminate/database

# If not, install it
composer require illuminate/database illuminate/support
```

## 🧹 Cleanup

To stop and remove the PostgreSQL container:
```bash
docker-compose down
```

To also remove volumes (deletes all database data):
```bash
docker-compose down -v
```

To remove only data but keep the configuration:
```bash
docker-compose down
docker volume rm laravel-pgsql-partition_postgres_data
```

## 📚 Tested Features

The script demonstrates:

- ✅ Creating partitioned tables
- ✅ Adding RANGE partitions (by year)
- ✅ Listing existing partitions
- ✅ Inserting data into partitions
- ✅ Querying partitioned tables
- ✅ Dropping partitions

## 🔗 Useful Links

- [PostgreSQL Partitioning Documentation](https://www.postgresql.org/docs/current/ddl-partitioning.html)
- [Main package README](README.md)

## 📝 Notes

- Data inserted during tests remains in the database until the container or volume is removed
- The script automatically drops the `orders` table if it already exists before creating a new one
- To test other features, modify the `test-partition.php` script

## 🎯 Next Steps

After running the basic tests successfully, you can:

1. Modify `test-partition.php` to test other features
2. Test other partition types (LIST, HASH)
3. Test advanced operations (DETACH, ATTACH, VACUUM, ANALYZE, REINDEX)
4. Create custom tests for specific use cases
