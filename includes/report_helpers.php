<?php
declare(strict_types=1);

/**
 * Shared attendance report filters.
 *
 * Empty class_id: all permitted classes.
 * Empty dates: no date boundary.
 */
function attendance_report_filters(array $input): array
{
    $rawClass = $input['class_id'] ?? '';
    $rawFrom = $input['from'] ?? '';
    $rawTo = $input['to'] ?? '';

    if (!is_string($rawClass) && !is_int($rawClass)) {
        throw new RuntimeException('Invalid class selection.');
    }

    if (!is_string($rawFrom) || !is_string($rawTo)) {
        throw new RuntimeException('Invalid date filters.');
    }

    $rawClass = trim((string) $rawClass);
    $rawFrom = trim($rawFrom);
    $rawTo = trim($rawTo);

    $classId = null;

    if ($rawClass !== '') {

        $parsedId = filter_var(
            $rawClass,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!is_int($parsedId)) {
            throw new RuntimeException(
                'Invalid class selection.'
            );
        }

        $classId = $parsedId;
    }

    foreach (
        ['From' => $rawFrom, 'To' => $rawTo]
        as $label => $value
    ) {

        if ($value === '') {
            continue;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value
        );

        if (
            !$date ||
            $date->format('Y-m-d') !== $value
        ) {
            throw new RuntimeException(
                $label . ' date must be valid.'
            );
        }
    }

    if (
        $rawFrom !== '' &&
        $rawTo !== '' &&
        $rawFrom > $rawTo
    ) {
        throw new RuntimeException(
            'From date cannot be after To date.'
        );
    }

    return [
        'class_id' => $classId,
        'from' => $rawFrom !== '' ? $rawFrom : null,
        'to' => $rawTo !== '' ? $rawTo : null,
    ];
}

/**
 * Present and Late count as attended.
 * Only recorded entries enter the denominator.
 */
function attendance_report_rate(
    int $present,
    int $late,
    int $recorded
): string {

    if ($recorded === 0) {
        return 'N/A';
    }

    return number_format(
        ($present + $late) * 100 / $recorded,
        1
    ) . '%';
}
