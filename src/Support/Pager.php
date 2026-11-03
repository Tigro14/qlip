<?php

declare(strict_types=1);

namespace Qlip\Support;

/**
 * Numero de page d une liste.
 *
 * Le nombre de pages vient d un COUNT, pas des lignes lues : sinon la derniere page, quand
 * elle n est pas pleine, ne serait jamais proposee. Le numero demande est borne a ce que la
 * liste peut donner : une valeur hors plage ne part pas dans la requete, parce qu un offset
 * negatif ou une page vide est une reponse, pas une donnee.
 *
 * Le nom du parametre est choisi par le code, donc chaque liste d une meme page a son propre
 * numero : une liste ne deplace pas les autres.
 */
final class Pager
{
    public const PER_PAGE = 50;

    private string $param;
    private int $count;
    private int $page;

    public function __construct(string $param, int $count, int $requested)
    {
        $this->param = $param;
        $this->count = max(0, $count);
        $this->page = min(max(1, $requested), $this->pages());
    }

    /** Element de l URL qui porte le numero de cette liste. */
    public function param(): string
    {
        return $this->param;
    }

    /** Lignes de la liste, avant la page. */
    public function count(): int
    {
        return $this->count;
    }

    /** Numero retenu, donc toujours dans la liste. */
    public function page(): int
    {
        return $this->page;
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->count / self::PER_PAGE));
    }

    public function offset(): int
    {
        return ($this->page - 1) * self::PER_PAGE;
    }
}
