
## CSWeb

This repository contains the code for CSWeb, the web application that allows users to securely transfer cases or files between client devices and a web server.

CSWeb is coded in PHP. Detailed API documentation can be found in the file [src/AppBundle/CSPro/swagger.json](https://github.com/csprousers/csweb/blob/main/src/AppBundle/CSPro/swagger.json).


## Private Development, Public Repository

Most development on CSWeb occurs on a [private repository](https://github.com/CSProDevelopment/CSWeb). The commits in this public repository are snapshots of the development that occurs on the private repository.

If you submit a pull request that is accepted by the development team, the pull request will be processed in this public repository and/or the private repository. Regardless, the changes will be incorporated as part of the next code snapshot.


## Contributing

We welcome contributions from everyone! If you'd like to contribute, please follow these guidelines:

- All contributions must be submitted via a [pull request](https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/proposing-changes-to-your-work-with-pull-requests/about-pull-requests) to the [dev](https://github.com/csprousers/csweb/tree/dev) branch.
- Ensure that the pull request is well-documented and includes a clear description of the changes.
- Follow the project's coding style and best practices.

Thank you for helping improve this project!


## Requirements ##

- Apache or IIS with mod_rewrite/URL rewrite
- MySQL 5.5.3+
- PHP 5.5.9+ with the following modules:
    - file_info
    - pdo
    - pdo_mysql
    - curl (or enable set allow_url_fopen in php.ini)
    - openssl


## Setup ##

1. Copy the source code to your www directory (so you have www/csweb).
2. Make sure that the directories *var*, *var/logs*, and *app* are wri by the web server user.
3. Create a MySQL database and a user with read/write access to the database.
4. In a browser go to <yourserverurl>/csweb/setup and follow the setup wizard.


## Usage ##

Login to the web interface (<yourserverurl>/csweb/) to add users and upload dictionaries.

In your CSPro application in the synchronization options enter the URL of your server (<yourserverurl>/csweb/api) or in your application logic use the syncconnect/syncdata/syncfile functions to upload/download data files to your server.
