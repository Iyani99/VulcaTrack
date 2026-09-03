# VulcaTrack

**VulcaTrack: Sales and Inventory with On-the-Go Services**

VulcaTrack is a web-based management system developed for **Gerald Tabayag Vulcanizing Shop**. The system combines customer-facing services, customer and vehicle management, in-shop sales and inventory, and On-the-Go (OTG) roadside vulcanizing service requests into one system.

The project is developed as a **BSIT student project**, with emphasis on maintainability, modularity, separation of concerns, understandable code, and a reasonable project scope.

---

## Features

### Customer / Public

- Customer registration and login
- Customer logout
- Customer dashboard
- Customer profile management
- Password changing
- Saved vehicle management
- Add, edit, deactivate, and restore vehicles
- Request On-the-Go roadside vulcanizing services
- Browser-based location capture
- Manual map location selection
- OTG request history
- View individual service request details
- View request status and frozen ETA
- View assigned Tireman information when applicable

### Admin

- Separate administrator authentication
- Admin dashboard and management functions
- Customer management
- Vehicle and service request management
- Tireman assignment for accepted OTG requests
- Product and service management
- Inventory management
- In-shop Point of Sale (POS)
- Walk-in customer sales
- Registered customer sales
- Printable sales receipts

### On-the-Go Services

OTG requests use the following statuses:

- `pending`
- `accepted`
- `rejected`
- `completed`

Customers must have an authenticated account to submit an OTG request.

The system captures the customer's location using latitude and longitude. Estimated travel time is calculated when the request is created and stored as a frozen value.

The system does **not** provide live Tireman GPS tracking or continuously updated ETA.

---

## Technology Stack

| Component | Technology |
|---|---|
| Backend | PHP 8.0.30 |
| Database | MariaDB 10.4.32 |
| Web Server | Apache 2.4 |
| Local Environment | XAMPP |
| Database Administration | phpMyAdmin |
| Frontend | HTML5, CSS3, JavaScript |
| UI Components | Vue.js where appropriate |
| Maps | Leaflet + OpenStreetMap |
| Design / Prototype | Figma |

### Technologies Not Used

The project does not use the following as part of the approved architecture:

- Laravel
- React
- Node.js runtime
- Firebase
- PostgreSQL

---

## System Architecture

VulcaTrack follows a modular PHP-based structure.

The application separates responsibilities between:

- Configuration
- Database access
- Authentication
- Repositories
- Validation
- Business logic
- Customer pages
- Administrative pages
- Shared UI components
- Frontend assets

The project does not use a large enterprise framework. The architecture is intentionally kept understandable and appropriate for the project's scope.

---

## Database

The application uses **8 database tables**:

1. `customers`
2. `admins`
3. `tiremen`
4. `vehicles`
5. `items`
6. `sales`
7. `sale_items`
8. `service_requests`

The database schema is defined in:

```text
database/schema.sql