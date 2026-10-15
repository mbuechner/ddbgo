@echo off
setlocal DisableDelayedExpansion
rem Use the same PHP launcher for both this command and Drush's subprocesses.
rem Keep paths relative to this file, independently of the working directory.
set "DDBGO_DRUSH_PROJECT=%~dp0"
set "DDBGO_DRUSH_PROJECT=%DDBGO_DRUSH_PROJECT:\=/%"
rem Use Composer's public binary proxy, not Drush's internal package layout.
if not exist "%~dp0vendor\bin\drush.php" (
  echo Drush PHP entry point missing. Run composer install and check the Drush version. 1>&2
  exit /b 1
)
php "%~dp0vendor\bin\drush.php" --alias-path="%~dp0drush\sites" @ddbgo.windows %*
exit /b %ERRORLEVEL%
