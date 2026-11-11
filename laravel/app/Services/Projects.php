<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

// Les clones git de ~/code dans lesquels travaillent les tâches
class Projects
{
    // nom ou owner/nom ; « worktrees » est le dossier des worktrees des tâches
    public function isValidName(string $name): bool
    {
        return preg_match('#^([A-Za-z0-9-]+/)?[A-Za-z0-9_-][A-Za-z0-9._-]*$#', $name)
            && $this->directory($name) !== 'worktrees';
    }

    public function path(string $name): string
    {
        return config('etabli.code_dir').'/'.$this->directory($name);
    }

    public function isCloned(string $name): bool
    {
        return File::isDirectory($this->path($name).'/.git');
    }

    // Échoue si le repo n'existe pas sur GitHub ou si le token gh n'y a pas accès
    public function cloneFromGitHub(string $name): bool
    {
        $repo = str_contains($name, '/') ? $name : config('etabli.github_owner')."/{$name}";

        return Process::timeout(300)
            ->run(['gh', 'repo', 'clone', $repo, $this->path($name)])
            ->successful();
    }

    // Un projet local, sans remote, avec un commit initial pour pouvoir créer des worktrees
    public function create(string $name): void
    {
        $path = $this->path($name);
        File::ensureDirectoryExists($path);

        Process::path($path)->run(['git', 'init', '-q', '-b', 'main'])->throw();
        Process::path($path)->run(['git', 'commit', '-q', '--allow-empty', '-m', 'Initialisation'])->throw();
    }

    private function directory(string $name): string
    {
        return Str::afterLast($name, '/');
    }
}
