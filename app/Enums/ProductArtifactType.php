<?php

namespace App\Enums;

enum ProductArtifactType: string
{
    case SourcePackage = 'source_package';
    case Documentation = 'documentation';
}
