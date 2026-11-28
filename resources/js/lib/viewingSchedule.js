export const viewingDate = (date) => new Date(`${date}T00:00:00`).toLocaleDateString("en-NZ", {
    weekday: "long", day: "numeric", month: "long", year: "numeric",
});

export function viewingTime(time) {
    const hour = Number(time.slice(0, 2));
    return `${hour % 12 || 12}:${time.slice(3)} ${hour < 12 ? "am" : "pm"}`;
}
