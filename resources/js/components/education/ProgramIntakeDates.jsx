import { buildProgramIntakes } from "../../lib/programIntakes";

export default function ProgramIntakeDates({ student, format = value => value }) {
    if (!Array.isArray(student.program_intakes) || !student.program_intakes.length) {
        return student.intake ? <span>{format(student.intake)}</span> : <span className="text-gray-300">—</span>;
    }
    return <ul aria-label="Program intake dates" className="space-y-1 whitespace-normal max-w-[240px]">
        {buildProgramIntakes(student.program, student.program_intakes).map(row => <li key={row.program}>
            <span className="block text-[10px] text-gray-500">{row.program}</span>
            <span className="text-gray-700">{row.intake ? format(row.intake) : "Not set"}</span>
        </li>)}
    </ul>;
}
