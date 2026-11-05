# Cloud-Based Small-Batch Manufacturing Changeover Time Analysis and Job-Sequence Optimizer

A PHP and MySQL application that compares production time estimates for a small-batch manufacturing job sequence before and after grouping jobs by product family.

## Project Overview

In small-batch manufacturing, changing from one product family to another can require machine changeover work, such as replacing or adjusting a die on a mechanical power press. These changeovers add time to the production schedule.

This project demonstrates a simple way to estimate that impact. A user enters a list of manufacturing jobs, their product families and processing times, along with an estimated setup duration for each family change. The application compares the entered job sequence with an alternative sequence grouped by product family.

The application is a scenario-based estimation tool. It is intended to demonstrate the effect of grouping jobs on estimated changeover time; it is not a full production-planning system or a guarantee of globally optimal scheduling.

## How It Works

1. Enter each job's job code, product family and processing time.
2. Enter the estimated setup time required for each change between product families.
3. Analyse the jobs in their original order.
4. Compare the result with an alternative sequence grouped by product family.
5. Review the estimated setup time and total production time for both sequences.
6. Save and revisit previous analyses through the history page.

### Setup-Time Calculation

The setup duration is entered by the user for each analysis. The application applies that value whenever two consecutive jobs belong to different product families.

- The first job does not incur a changeover.
- Consecutive jobs from the same family do not incur a changeover.
- A change from one family to another adds the user-entered setup duration.
- A setup duration of zero is allowed.
- The setup duration can be changed for each new analysis.

The example-data feature pre-fills a 10-minute value as an illustration. It is not a permanent or mandatory project setting.

### Product-Family Examples

The example manufacturing scenario uses:

| Family code | Example product |
|---|---|
| A | Steel washers |
| B | Metal retaining clips |
| C | Small mounting brackets |

When jobs are grouped, their original relative order within each product family is preserved.

The processing time remains the same in both sequences. Any difference in the estimated total time comes from the changeover setup time.

## Main Features

- User-entered manufacturing job details.
- User-configurable setup duration per family change.
- Comparison of the original job order and family-grouped order.
- Estimated setup time and total production time for both sequences.
- Analysis history stored in MySQL.
- Health-check endpoint for checking application/database connectivity.
- Docker-based application and database configuration for portable deployment.

## Technology Stack

- **Application:** PHP 8.3
- **Web server:** Apache
- **Database:** MySQL 8.0
- **Containerization:** Docker and Docker Compose
- **Cloud deployment target:** Ubuntu Server 22.04 LTS on an Azure virtual machine

The application was initially developed and tested in a local PHP/MySQL environment using Laragon. It was subsequently packaged with Docker and Docker Compose for container-based deployment. The Azure virtual machine is the target environment for the cloud deployment demonstration.

## Project Structure

```text
changeover-tool/
│
├── index.php
├── history.php
├── health.php
│
├── config/
│   └── database.php
│
├── sql/
│   └── schema.sql
│
├── Dockerfile
├── docker-compose.yml
├── .dockerignore
├── .gitignore
├── .env.example
└── README.md
```

The actual `.env` file is intentionally excluded from the repository because it contains environment-specific credentials. Use `.env.example` as a template.

## Run the Application with Docker Compose

### Prerequisites

- Docker Engine
- Docker Compose plugin
- A web browser

### 1. Configure environment variables

Create a `.env` file in the project root using `.env.example` as the template.

Replace the placeholder values with your own strong database passwords:

```dotenv
DB_PASSWORD=your_database_password
MYSQL_ROOT_PASSWORD=your_root_password
```

Do not commit the `.env` file to GitHub.

### 2. Build and start the containers

From the project directory, run:

```bash
docker compose up -d --build
```

Docker Compose builds the PHP/Apache application image and starts the application and MySQL containers.

### 3. Initialise the database tables

On the first run, apply the SQL schema from the project directory:

```bash
docker compose exec -T db sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD"' < sql/schema.sql
```

This creates the application database and its tables. The command may be run again because the schema uses `IF NOT EXISTS` for database and table creation.

### 4. Open the application

In a browser, visit:

```text
http://localhost:8080
```

The application is mapped to port `8080` on the host.

### 5. Check application health

Visit:

```text
http://localhost:8080/health.php
```

The health endpoint can be used to check the application's database connectivity.

### 6. Stop the containers

To stop the application and database containers, run:

```bash
docker compose down
```

The MySQL data is stored in the named Docker volume `mysql_data`. Stopping the containers does not remove that volume.

## Important Notes

- The setup duration is an input for each analysis, not a fixed application-wide value.
- The family-grouped sequence is a straightforward grouping heuristic. It does not claim to solve every possible manufacturing scheduling constraint or guarantee a globally optimal schedule.
- The example products and job sequences are illustrative.
- Keep credentials and other sensitive configuration out of source control.
