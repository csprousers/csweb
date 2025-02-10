@ECHO OFF
setlocal DISABLEDELAYEDEXPANSION
SET BIN_TARGET=%~dp0/../vitexsoftware/phpunit-skeleton-generator/phpunit-skelgen
php "%BIN_TARGET%" %*
