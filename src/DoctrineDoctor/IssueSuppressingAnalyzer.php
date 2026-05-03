<?php

namespace App\DoctrineDoctor;

use AhmedBhs\DoctrineDoctor\Analyzer\AnalyzerInterface;
use AhmedBhs\DoctrineDoctor\Collection\IssueCollection;
use AhmedBhs\DoctrineDoctor\Collection\QueryDataCollection;

final class IssueSuppressingAnalyzer implements AnalyzerInterface
{
    /**
     * @param list<string> $suppressedTitles
     */
    public function __construct(
        private readonly AnalyzerInterface $inner,
        private readonly array $suppressedTitles = [],
    ) {
    }

    public function analyze(QueryDataCollection $queryDataCollection): IssueCollection
    {
        $suppressedTitles = array_flip($this->suppressedTitles);
        $issues = [];

        foreach ($this->inner->analyze($queryDataCollection) as $issue) {
            if (isset($suppressedTitles[$issue->getTitle()])) {
                continue;
            }

            $issues[] = $issue;
        }

        return IssueCollection::fromArray($issues);
    }
}
