<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Worker;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;

/**
 * Collects the classes marked #[Singleton], for SingletonStateRule.
 *
 * @implements Collector<InClassNode, list<string>>
 */
final class SingletonAttributeCollector implements Collector
{
    private const ATTRIBUTE = 'Illuminate\Container\Attributes\Singleton';

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<string>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        foreach ($node->getClassReflection()->getAttributes() as $attribute) {
            if ($attribute->getName() === self::ATTRIBUTE) {
                return [$node->getClassReflection()->getName()];
            }
        }

        return null;
    }
}
