# Steps followed to setup PHP environment

Download Uwamp server version 3.1.0 from https://www.uwamp.com/en/?page=download 

For PHP 7.2+ you need : Download VC15 2017 vc_redist.x86.exe

Upgrade PHP version to 7.2.7 from Uwamp

Download and install composer for dependency management - https://getcomposer.org/doc/00-intro.md

This is a Slim Framework 4 Skeleton Application and been created using following command. Learn more about Slimframework from http://www.slimframework.com/docs/v4/

```bash
composer create-project slim/slim-skeleton oauth2-example-slim-php
```

Installed dependencies like phpview and Guzzle using command-

```bash
composer require <dependency-name>
```
Note that I have changed the port number to 9090 in composer.json. To run the application in development, you can run these commands 

```bash
cd [my-app-name]
composer start
```
After that, open `http://localhost:9090` in your browser. Checkout code written for Deere OAuth2 handshake in app/routes.php

Enjoy coding!
