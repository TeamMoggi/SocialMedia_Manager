<x-filament-panels::page>
    @assets
        <script src="{{ asset('vendor/fullcalendar/6.1.15/index.global.min.js') }}"></script>
    @endassets

    <div style="display:flex;flex-wrap:wrap;gap:.5rem 1.25rem;font-size:.85rem;align-items:center">
        @foreach ($states as $state)
            <span style="display:inline-flex;align-items:center;gap:.4rem">
                <span style="width:.75rem;height:.75rem;border-radius:999px;background:{{ $state->color() }};display:inline-block"></span>
                {{ $state->label() }}
            </span>
        @endforeach
        <span style="opacity:.7">Times in {{ $zone }}. Drag a post to change its time; an approved post stays approved.</span>
    </div>

    <div wire:ignore>
        <div id="smm-calendar"></div>
    </div>

    @script
        <script>
            const el = document.getElementById('smm-calendar');
            const calendar = new FullCalendar.Calendar(el, {
                timeZone: 'UTC',
                now: @js($now),
                initialView: 'timeGridWeek',
                firstDay: 1,
                slotMinTime: '06:00:00',
                height: 'auto',
                nowIndicator: true,
                allDaySlot: false,
                headerToolbar: { left: 'prev,next today', center: 'title', right: 'timeGridWeek,dayGridMonth' },
                buttonText: { today: 'Today', week: 'Week', month: 'Month' },
                eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                editable: true,
                eventDurationEditable: false,
                eventAllow: (drop) => drop.start >= new Date(@js($now)),
                events: (info, success, failure) => {
                    $wire.calendarEvents(info.startStr, info.endStr).then(success).catch(failure);
                },
                eventDrop: (info) => {
                    $wire.movePost(parseInt(info.event.id), info.event.startStr).then((result) => {
                        if (! result.ok) {
                            info.revert();
                        }
                        new FilamentNotification()
                            .title(result.message)
                            .status(result.ok ? 'success' : 'danger')
                            .send();
                    });
                },
            });
            calendar.render();
        </script>
    @endscript
</x-filament-panels::page>
