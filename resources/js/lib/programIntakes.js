export const programTitles = text => String(text || "").split(" · ").map(title => title.trim()).filter(Boolean);

// Dates are paired by title, never by a changing array index. A legacy shared
// intake belongs to the first program only; additional dates remain unknown.
export function buildProgramIntakes(text, saved, legacy = "") {
    return programTitles(text).map((program, index) => ({
        program,
        intake: Array.isArray(saved)
            ? (saved.find(row => row.program === program)?.intake || "")
            : (index === 0 ? legacy || "" : ""),
    }));
}

export const isIntakeDate = value => /^\d{4}-\d{2}-\d{2}$/.test(value || "");
