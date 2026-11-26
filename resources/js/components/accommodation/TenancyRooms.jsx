export default function TenancyRooms({ rooms = [], selected, onChange, errors }) {
    const messages = Object.entries(errors).filter(([key]) => key.startsWith("assigned_room_ids"));
    if (!rooms.length && !messages.length) return null;

    return (
        <fieldset className="space-y-2 rounded-xl border border-gray-200 p-4">
            <legend className="px-1 text-sm font-semibold text-gray-900">Assigned rooms</legend>
            <p className="text-xs text-gray-600">Select every room this tenancy occupies. Shared rooms can be selected on more than one tenancy.</p>
            <div className="grid gap-2 sm:grid-cols-2">
                {rooms.map(room => (
                    <label key={room.id} className="flex items-center gap-2 text-sm text-gray-800">
                        <input type="checkbox" checked={selected.includes(room.id)}
                            onChange={event => onChange(event.target.checked
                                ? [...selected, room.id]
                                : selected.filter(id => id !== room.id))} />
                        {room.name}
                    </label>
                ))}
            </div>
            {messages.map(([key, message]) => <p key={key} role="alert" className="text-xs text-rose-700">{message}</p>)}
        </fieldset>
    );
}
