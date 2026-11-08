<?php

namespace Tests\Feature\Education;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_open_the_program_guide(): void
    {
        $this->get('/international-student-program-guide')
            ->assertOk()
            ->assertViewIs('education.program-guide')
            ->assertSee('International Student Program Guide')
            ->assertSee('/program-guide/pages/01.webp')
            ->assertSee('/program-guide/program-guide.pdf')
            ->assertSee('<option value="45">45</option>', false);
    }
}
