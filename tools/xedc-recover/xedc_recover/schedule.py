"""Render systemd timer and cron lines. Installing into /etc is refused on the production host."""

from __future__ import annotations

from pathlib import Path

from .safety import assert_cron_install_target

SERVICE = """[Unit]
Description=XEDC encrypted backup

[Service]
Type=oneshot
Nice=19
IOSchedulingClass=idle
ExecStart={binary} --config {config} backup create --type {backup_type} --allow-live-read
"""

TIMER = """[Unit]
Description=XEDC daily backup

[Timer]
OnCalendar={calendar}
Persistent=true
RandomizedDelaySec=20m

[Install]
WantedBy=timers.target
"""

CRON = "{minute} {hour} * * * {binary} --config {config} backup create --type {backup_type} --allow-live-read >> {log} 2>&1\n"


def render(directory: str, *, binary: str, config: str, backup_type: str = "full", calendar: str = "daily", hour: int = 19, minute: int = 15, log: str = "/var/log/xedc-recover.log") -> dict:
    target = assert_cron_install_target(directory)
    target.mkdir(parents=True, exist_ok=True)
    service = target / "xedc-recover-backup.service"
    timer = target / "xedc-recover-backup.timer"
    cron = target / "xedc-recover.cron"
    service.write_text(SERVICE.format(binary=binary, config=config, backup_type=backup_type), encoding="utf-8")
    timer.write_text(TIMER.format(calendar=calendar), encoding="utf-8")
    cron.write_text(CRON.format(minute=minute, hour=hour, binary=binary, config=config, backup_type=backup_type, log=log), encoding="utf-8")
    return {"service": str(service), "timer": str(timer), "cron": str(cron)}


def uninstall(directory: str) -> list[str]:
    target = assert_cron_install_target(directory)
    removed = []
    for name in ("xedc-recover-backup.service", "xedc-recover-backup.timer", "xedc-recover.cron"):
        path = target / name
        if path.is_file():
            path.unlink()
            removed.append(str(path))
    return removed
