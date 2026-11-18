<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Talent\Imports\LinkedInProfileJsonAdapter;
use App\Services\Talent\CandidateSourceFactory;
use App\Services\Talent\Sources\JobVisionSource;
use App\Services\Talent\Sources\PublicProfileSource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LinkedInProfileJsonAdapterTest extends TestCase
{
    #[Test]
    public function it_previews_a_normalized_linkedin_profile_with_field_provenance_without_persisting(): void
    {
        $adapter = new LinkedInProfileJsonAdapter();

        $preview = $adapter->preview([
            'profile' => [
                'fullName' => 'Amina Example',
                'headline' => 'Staff Engineer',
                'locationName' => 'Tehran',
                'publicIdentifier' => 'amina-example',
                'profileUrl' => 'https://www.linkedin.com/in/amina-example?trk=private-token',
                'skills' => [['name' => 'PHP'], ['name' => 'Laravel']],
                'experience' => [[
                    'title' => 'Engineer',
                    'company' => 'Example Co',
                    'description' => 'Email amina@example.test; phone +1 202 555 0144',
                    'startDate' => '2021',
                ]],
                'education' => [[
                    'degree' => 'BSc',
                    'school' => 'Example University',
                ]],
                'email' => 'amina@example.test',
                'phone' => '+1 202 555 0144',
            ],
            'accessToken' => 'must-not-escape',
        ]);

        $this->assertSame('linkedin', $preview['source']);
        $this->assertSame('linkedin:amina-example', $preview['source_record_key']);
        $this->assertSame('https://www.linkedin.com/in/amina-example', $preview['profile_url']);
        $this->assertSame('Amina Example', $preview['profile']['full_name']);
        $this->assertSame('Staff Engineer', $preview['profile']['headline']);
        $this->assertSame('Tehran', $preview['profile']['location']);
        $this->assertSame(['PHP', 'Laravel'], $preview['profile']['skills']);
        $this->assertSame('Engineer', $preview['profile']['experience'][0]['title']);
        $this->assertSame('Example University', $preview['profile']['education'][0]['institution']);
        $this->assertSame('fullName', $preview['provenance']['profile.full_name']);
        $this->assertTrue($preview['requires_human_approval']);
        $this->assertArrayNotHasKey('persisted', $preview);
        $this->assertStringNotContainsString('must-not-escape', json_encode($preview, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('amina@example.test', json_encode($preview, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('+1 202 555 0144', json_encode($preview, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_keeps_missing_fields_unknown_and_does_not_infer_a_profile_identifier(): void
    {
        $preview = (new LinkedInProfileJsonAdapter())->preview([
            'full_name' => 'Amina Example',
            'headline' => 'Engineer',
        ]);

        $this->assertNull($preview['source_record_key']);
        $this->assertNull($preview['profile_url']);
        $this->assertSame([], $preview['profile']['skills']);
        $this->assertSame([], $preview['profile']['experience']);
        $this->assertSame([], $preview['profile']['education']);
        $this->assertTrue($preview['requires_human_approval']);
    }

    #[Test]
    public function it_maps_common_linkedin_aliases_and_nested_object_values(): void
    {
        $preview = (new LinkedInProfileJsonAdapter())->preview([
            'fullName' => 'Amina Example',
            'headline' => 'Staff Engineer',
            'location' => ['name' => 'Tehran'],
            'publicProfileUrl' => 'https://www.linkedin.com/in/amina-example?trk=private-token',
            'skills' => [['name' => 'PHP'], ['name' => 'Laravel']],
            'experience' => [[
                'title' => 'Staff Engineer',
                'companyName' => 'Example Co',
                'startDate' => '2021',
            ]],
            'education' => [[
                'degree' => 'BSc',
                'school' => ['name' => 'Example University'],
                'fieldOfStudy' => 'Computer Science',
            ]],
        ]);

        $this->assertSame('linkedin:amina-example', $preview['source_record_key']);
        $this->assertSame('Example Co', $preview['profile']['experience'][0]['company']);
        $this->assertSame('Example University', $preview['profile']['education'][0]['institution']);
        $this->assertSame('Computer Science', $preview['profile']['education'][0]['field_of_study']);
    }

    #[Test]
    public function it_rejects_non_profile_json_without_echoing_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No supported LinkedIn profile fields');

        (new LinkedInProfileJsonAdapter())->preview([
            'secret' => 'do-not-include-in-errors',
        ]);
    }

    #[Test]
    public function it_rejects_non_linkedin_profile_urls(): void
    {
        $preview = (new LinkedInProfileJsonAdapter())->preview([
            'full_name' => 'Amina Example',
            'profile_url' => 'https://evil.example/in/amina',
        ]);

        $this->assertNull($preview['profile_url']);
        $this->assertNull($preview['source_record_key']);
    }

    #[Test]
    public function it_does_not_change_existing_candidate_source_factory_mappings(): void
    {
        $factory = new CandidateSourceFactory();

        $this->assertInstanceOf(JobVisionSource::class, $factory->make('jobvision'));
        $this->assertInstanceOf(PublicProfileSource::class, $factory->make('linkedin'));
    }

    #[Test]
    public function it_preserves_existing_jobvision_normalization(): void
    {
        $profile = (new JobVisionSource())->parse([
            'candidate_id' => 'jv-101',
            'personal_info' => ['full_name' => 'Synthetic Candidate'],
            'skills' => ['PHP'],
        ]);

        $this->assertSame('jv-101', $profile['external_id']);
        $this->assertSame('Synthetic Candidate', $profile['full_name']);
        $this->assertSame(['PHP'], $profile['skills']);
    }
}
