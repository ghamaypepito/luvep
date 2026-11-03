import TaskBoardPage from "@/components/task-board/TaskBoardPage";
import TaskDetailModal from "@/components/task-board/TaskDetailModal";
import { useEffect, useState } from "react";

export default function FinanceTasks(props) {
    const [selectedTask, setSelectedTask] = useState(props.selected_task ?? null);
    useEffect(() => setSelectedTask(props.selected_task ?? null), [props.selected_task]);

    return <>
        <TaskBoardPage department="finance" {...props} />
        {selectedTask && <TaskDetailModal task={selectedTask} onClose={() => setSelectedTask(null)} />}
    </>;
}
