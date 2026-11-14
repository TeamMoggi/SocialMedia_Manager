@echo off
title SocialMedia Manager - update and start
rem Brings in the changes you approved on GitHub, then starts the app and opens it.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\windows\update.ps1" %*
echo.
pause
