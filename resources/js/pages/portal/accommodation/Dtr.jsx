import DtrPage from '@/pages/dtr/DtrPage';
import SharedDtrTasks from './SharedDtrTasks';

export default function AccommodationDtr({ sharedAccommodationDtr, ...props }) {
    return <>
        {sharedAccommodationDtr && <SharedDtrTasks board={sharedAccommodationDtr} />}
        <DtrPage {...props} />
    </>;
}
