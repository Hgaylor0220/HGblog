<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;
use Twig\TemplateWrapper;

/* core/themes/olivero/templates/field/field--comment.html.twig */
class __TwigTemplate_e8ea0e34fa1fbe58b6fc8e47364ee807 extends Template
{
    private Source $source;
    /**
     * @var array<string, Template>
     */
    private array $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
        $this->sandbox = $this->extensions[SandboxExtension::class];
        $this->checkSecurity();
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 30
        yield "
";
        // line 31
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Drupal\Core\Template\TwigExtension']->attachLibrary("olivero/comments"), "html", null, true);
        yield "
<section";
        // line 32
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["attributes"] ?? null), "setAttribute", ["data-drupal-selector", "comments"], "method", false, false, true, 32), "addClass", ["comments"], "method", false, false, true, 32), "html", null, true);
        yield ">

  ";
        // line 34
        if ((($tmp =  !($context["label_hidden"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 35
            yield "    ";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["title_prefix"] ?? null), "html", null, true);
            yield "
    <h2";
            // line 36
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["title_attributes"] ?? null), "addClass", ["comments__title"], "method", false, false, true, 36), "html", null, true);
            yield ">";
            // line 37
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["label"] ?? null), "html", null, true);
            // line 38
            if ((($tmp = ($context["comments"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 39
                yield "<span class=\"comments__count\">";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["comment_count"] ?? null), "html", null, true);
                yield "</span>";
            }
            // line 41
            yield "</h2>
    ";
            // line 42
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["title_suffix"] ?? null), "html", null, true);
            yield "
  ";
        }
        // line 44
        yield "
  ";
        // line 45
        if ((($tmp = ($context["comment_form"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 46
            yield "    <div class=\"add-comment\">
      ";
            // line 47
            if ((($tmp = ($context["user_picture"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 48
                yield "      <div class=\"add-comment__picture-wrapper\">
        <div class=\"add-comment__picture\">
          ";
                // line 50
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["user_picture"] ?? null), "html", null, true);
                yield "
        </div>
      </div>
      ";
            }
            // line 54
            yield "      <div class=\"add-comment__form\">
        ";
            // line 55
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["comment_form"] ?? null), "html", null, true);
            yield "
      </div>
    </div>
  ";
        }
        // line 59
        yield "
  ";
        // line 60
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["comments"] ?? null), "html", null, true);
        yield "

</section>
";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["attributes", "label_hidden", "title_prefix", "title_attributes", "label", "comments", "comment_count", "title_suffix", "comment_form", "user_picture"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "core/themes/olivero/templates/field/field--comment.html.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable(): bool
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo(): array
    {
        return array (  117 => 60,  114 => 59,  107 => 55,  104 => 54,  97 => 50,  93 => 48,  91 => 47,  88 => 46,  86 => 45,  83 => 44,  78 => 42,  75 => 41,  70 => 39,  68 => 38,  66 => 37,  63 => 36,  58 => 35,  56 => 34,  51 => 32,  47 => 31,  44 => 30,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "core/themes/olivero/templates/field/field--comment.html.twig", "/var/www/html/web/core/themes/olivero/templates/field/field--comment.html.twig");
    }
    
    public function checkSecurity()
    {
        static $tags = ["if" => 34];
        static $filters = ["escape" => 31];
        static $functions = ["attach_library" => 31];

        try {
            $this->sandbox->checkSecurity(
                ['if'],
                ['escape'],
                ['attach_library'],
                $this->source
            );
        } catch (SecurityError $e) {
            $e->setSourceContext($this->source);

            if ($e instanceof SecurityNotAllowedTagError && isset($tags[$e->getTagName()])) {
                $e->setTemplateLine($tags[$e->getTagName()]);
            } elseif ($e instanceof SecurityNotAllowedFilterError && isset($filters[$e->getFilterName()])) {
                $e->setTemplateLine($filters[$e->getFilterName()]);
            } elseif ($e instanceof SecurityNotAllowedFunctionError && isset($functions[$e->getFunctionName()])) {
                $e->setTemplateLine($functions[$e->getFunctionName()]);
            }

            throw $e;
        }

    }
}
