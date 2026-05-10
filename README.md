## <img src="https://raw.githubusercontent.com/TiramisuAddict/PI-3A-java/refs/heads/main/assets/Logo.png" width="25"> Momentum Web

![Symfony Version](https://img.shields.io/badge/Symfony-6.4-black?logo=symfony&logoColor=white)
![PHP Version](https://img.shields.io/badge/PHP-8.1.25-777BB4?logo=php&logoColor=white)
![Project Type](https://img.shields.io/badge/Project-Academic-blue)
![Status](https://img.shields.io/badge/Status-Finished-red)

<img src="https://raw.githubusercontent.com/TiramisuAddict/PI-3A-java/refs/heads/main/assets/Main-mockup.jpg" width="800">

## Overview
Momentum Web is an AI-powered HR management web platform designed to simplify recruitment and enterprise workflows through a modern and responsive interface.

The platform connects HR teams with candidates while also providing tools for employee management, project handling, requests management, and internal communication through a social feed system.

Built with Symfony 6.4 following an MVC architecture, the application focuses on scalability, maintainability, and user experience.

---
## Main Features & Contributions

| Feature / Branch | Contributors |
|------------------|-------------|
| Demand management | Team Larry |
| Training / Course management | Team Larry |
| Recruitment management | <a href="https://github.com/TiramisuAddict">TiramisuAddict</a> |
| Project management | Team Larry |
| User / Employee management | Team Larry |
| Social Feed / Posts management | Team Larry |

> **Note** : For more details about each feature, please refer to the corresponding branches in the repository.

---

## Installation

### Requirements
Make sure you have the following installed:

- PHP 8.1.25
- Composer
- MySQL
- Apache Server

Check your PHP version:

```bash
php --version
```

---

### Clone Repository

```bash
git clone https://github.com/TiramisuAddict/PI-3A-Web
```

Navigate to the project directory:

```bash
cd PI-3A-Web
```

---

### Install Dependencies

```bash
composer install --no-interaction
```

---

### Database Setup

Open Apache and MySQL services using XAMPP (or your preferred environment).

Import the provided `momentum.sql` database into MySQL.

---

### Run Project

```bash
php -S localhost:8000 -t public
```

Then open:

```txt
http://localhost:8000
```

---

## Technologies Used

- Symfony 6.4
- PHP 8.1
- MySQL
- Twig
- JavaScript
- HTML / CSS
- MVC Architecture

---
