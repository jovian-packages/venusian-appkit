<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\View\DateChanged;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Mail\View\ValueChanged;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\Contracts\Windows\Primitives\Align;
use Surface\Contracts\Windows\Primitives\ImageScaling;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\FontWeight;
use Surface\Contracts\Windows\Styling\TextAlignment;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('posts ButtonClicked with the path and uuid', function (): void {
    $window = driver()->open('main', 300, 200);
    $button = $window->column('m')->button('go', 'Go');
    $button->native()->performClick(null);

    expect(takeMail(session()))->toEqual([new ButtonClicked('main', 'm.go', $button->uuid())])
        ->and($button->label())->toBe('Go');
    $button->setLabel('Run');
    expect($button->native()->title())->toBe('Run');
});

it('styles a button and refuses clicks while disabled', function (): void {
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $button = $main->button('go', 'Go');
    $button->setFont(new FontSpec(17.0, FontWeight::BOLD))->setTextColor(Color::hex('#0000ff'));

    expect($button->native()->font()->pointSize())->toBe(17.0)
        ->and(NSFontManager::sharedFontManager()->weightOfFont($button->native()->font()))->toBeGreaterThanOrEqual(8)
        ->and(round($button->native()->contentTintColor()->blueComponent(), 2))->toBe(1.0);

    $button->setTextColor(null);
    $main->disable();
    $button->native()->performClick(null);
    expect($button->native()->contentTintColor())->toBeNull()
        ->and($button->native()->isEnabled())->toBeFalse()
        ->and($button->isEnabled())->toBeTrue()
        ->and(takeMail(session()))->toBe([]);

    $button->disable();
    $main->enable();
    expect($button->native()->isEnabled())->toBeFalse();
    $button->enable();
    expect($button->native()->isEnabled())->toBeTrue();
});

it('writes label text, wrap, alignment, font and colour to the native', function (): void {
    $window = driver()->open('main', 200, 200);
    $label = $window->column('m')->label('l', 'Hello');
    $defaultColor = $label->native()->textColor();
    [$builtBreak, $builtLines] = [$label->native()->lineBreakMode(), $label->native()->maximumNumberOfLines()];
    $label->setText('A longer line that has to wrap inside two hundred points of width')
        ->setWrap(true)
        ->setAlignment(TextAlignment::CENTER)
        ->setFont(new FontSpec(15.0, FontWeight::BOLD, 'Helvetica'))
        ->setTextColor(Color::rgb(255, 0, 0));
    $window->present();
    pumpFor(0.2);

    expect($label->native()->stringValue())->toBe('A longer line that has to wrap inside two hundred points of width')
        ->and($label->native()->maximumNumberOfLines())->toBe(0)
        ->and($label->native()->lineBreakMode())->toBe(NSLineBreakMode::WORD_WRAPPING)
        ->and($label->native()->alignment())->toBe(NSTextAlignment::CENTER)
        ->and($label->native()->font()->familyName())->toBe('Helvetica')
        ->and($label->native()->font()->pointSize())->toBe(15.0)
        ->and(round($label->native()->textColor()->redComponent(), 2))->toBe(1.0)
        // The frame sits 2pt outside the label's alignment rect on each side.
        ->and($label->size()[0])->toBeLessThanOrEqual(204)
        ->and($label->size()[1])->toBeGreaterThan(30);

    $label->setWrap(false)->setTextColor(null)->setFont(new FontSpec(12.0, FontWeight::REGULAR, 'No Such Family 9000'));
    pumpFor(0.1);
    expect($label->native()->maximumNumberOfLines())->toBe($builtLines)
        ->and($label->native()->lineBreakMode())->toBe($builtBreak)
        ->and($label->size()[1])->toBeLessThan(20)
        ->and($label->native()->textColor())->toEqual($defaultColor)
        ->and($label->native()->font()->pointSize())->toBe(12.0)
        ->and($label->native()->font()->familyName())->toBe(NSFont::systemFontOfSizeWeight(12.0, NSFont::WEIGHT_REGULAR)->familyName());
});

/** A width x height PNG at 72 dpi (NSImage sizes in points from the dpi) in the temp dir, removed by the caller. */
function pngFile(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imageresolution($image, 72, 72);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
    $path = tempnam(sys_get_temp_dir(), 'tk-image-').'.png';
    imagepng($image, $path);

    return $path;
}

it('shows an image at its natural size, scaled per mode, and refuses a file it cannot read', function (): void {
    $path = pngFile(40, 20);
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $image = $main->image('i', $path)->align(Align::START, Align::START);
    $window->present();
    pumpFor(0.2);

    expect($image->native()->layerContents())->toBeInstanceOf(NSImage::class)
        ->and($image->native()->layerContents()->size()->width)->toBe(40.0)
        ->and($image->native()->layerContentsGravity())->toBe(kCAGravityResizeAspect)
        ->and($image->native()->layerMasksToBounds())->toBeTrue()
        ->and($image->size())->toBe([40, 20]);

    foreach ([[ImageScaling::FILL, kCAGravityResizeAspectFill], [ImageScaling::CENTER, kCAGravityCenter], [ImageScaling::STRETCH, kCAGravityResize], [ImageScaling::FIT, kCAGravityResizeAspect]] as [$scaling, $gravity]) {
        $image->setScaling($scaling);
        expect($image->native()->layerContentsGravity())->toBe($gravity);
    }

    expect(fn () => $image->setFile('/nope/missing.png'))->toThrow(WindowException::class, 'missing.png')
        ->and($image->file())->toBe($path)
        ->and(fn () => $main->image('broken', '/nope/missing.png'))->toThrow(WindowException::class)
        ->and($window->view('m.broken'))->toBeNull();

    $image->setFile(null);
    pumpFor(0.1);
    expect($image->native()->layerContents())->toBeNull()
        ->and($image->size())->toBe([0, 0]);
    unlink($path);
});

it('draws horizontal and vertical separators as NSBox separators across their slot', function (): void {
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $line = $main->separator('line');
    $row = $main->row('r')->fill();
    $bar = $row->separator('bar', horizontal: false);
    $window->present();
    pumpFor(0.2);

    expect($line->native())->toBeInstanceOf(NSBox::class)
        ->and($line->native()->boxType())->toBe(NSBoxType::SEPARATOR)
        ->and($line->size()[0])->toBe(300)
        ->and($line->size()[1])->toBeLessThanOrEqual(5)
        ->and($bar->size()[0])->toBeLessThanOrEqual(5)
        ->and($bar->size()[1])->toBe($row->size()[1]);
});

it('spins a spinning indicator only while started', function (): void {
    $window = driver()->open('main', 300, 200);
    $spinner = $window->column('m')->spinner('s');

    expect($spinner->native())->toBeInstanceOf(NSProgressIndicator::class)
        ->and($spinner->native()->isIndeterminate())->toBeTrue()
        ->and($spinner->isSpinning())->toBeFalse();
    $spinner->start();
    expect($spinner->isSpinning())->toBeTrue();
    $spinner->stop();
    expect($spinner->isSpinning())->toBeFalse();
});

it('shows a fraction, or an indeterminate bar for null', function (): void {
    $window = driver()->open('main', 300, 200);
    $bar = $window->column('m')->progressBar('p', 0.25);

    expect($bar->native()->isIndeterminate())->toBeFalse()
        ->and($bar->native()->minValue())->toBe(0.0)
        ->and($bar->native()->maxValue())->toBe(1.0)
        ->and($bar->native()->doubleValue())->toBe(0.25);
    $bar->setFraction(null);
    expect($bar->native()->isIndeterminate())->toBeTrue();
    $bar->setFraction(0.5);
    expect($bar->native()->isIndeterminate())->toBeFalse()
        ->and($bar->native()->doubleValue())->toBe(0.5);
});

it('reports typing as TextChanged and Return as TextSubmitted; code changes post nothing', function (): void {
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $input = $main->textInput('in', 'start', 'type here');
    $secret = $main->textInput('pw', 'hunter2', secret: true);

    expect($input->native())->toBeInstanceOf(NSTextField::class)
        ->and($input->native())->not->toBeInstanceOf(NSSecureTextField::class)
        ->and($input->native()->stringValue())->toBe('start')
        ->and($input->native()->placeholderString())->toBe('type here')
        ->and($input->native()->isEditable())->toBeTrue()
        ->and($secret->native())->toBeInstanceOf(NSSecureTextField::class)
        ->and($secret->native()->stringValue())->toBe('hunter2');

    $input->native()->setStringValue('abc');
    NSNotificationCenter::defaultCenter()->postNotificationNameObject(NSControlTextDidChangeNotification, $input->native());
    $input->native()->performClick(null);

    expect(takeMail(session()))->toEqual([
        new TextChanged('main', 'm.in', $input->uuid(), 'abc'),
        new TextSubmitted('main', 'm.in', $input->uuid(), 'abc'),
    ])->and($input->value())->toBe('abc');

    $input->setValue('code')->setPlaceholder(null)->setFont(new FontSpec(16.0))->setTextColor(Color::hex('#00ff00'));
    $input->disable();
    expect(takeMail(session()))->toBe([])
        ->and($input->native()->stringValue())->toBe('code')
        ->and($input->native()->placeholderString())->toBeNull()
        ->and($input->native()->font()->pointSize())->toBe(16.0)
        ->and(round($input->native()->textColor()->greenComponent(), 2))->toBe(1.0)
        ->and($input->native()->isEnabled())->toBeFalse();
});

it('reports text area edits as TextChanged and keeps the text view inside its scroll view', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('notes', 'first')->fill();
    $window->present();
    pumpFor(0.2);
    takeMail(session());
    $text = $area->native()->documentView();

    expect($area->native())->toBeInstanceOf(NSScrollView::class)
        ->and($text)->toBeInstanceOf(NSTextView::class)
        ->and($text->string())->toBe('first')
        ->and($area->size())->toBe([300, 200]);

    $text->setString('second line');
    NSNotificationCenter::defaultCenter()->postNotificationNameObject(NSTextDidChangeNotification, $text);
    expect(takeMail(session()))->toEqual([new TextChanged('main', 'm.notes', $area->uuid(), 'second line')])
        ->and($area->value())->toBe('second line');

    $area->setValue('from code')->setFont(new FontSpec(18.0))->setTextColor(Color::hex('#0000ff'))->disable();
    expect(takeMail(session()))->toBe([])
        ->and($text->string())->toBe('from code')
        ->and($text->font()->pointSize())->toBe(18.0)
        ->and(round($text->textColor()->blueComponent(), 2))->toBe(1.0)
        ->and($text->isEditable())->toBeFalse();
    $area->enable();
    expect($text->isEditable())->toBeTrue();
});

it('toggles a checkbox, a switch and a toggle button from a click; code changes post nothing', function (): void {
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $check = $main->checkbox('c', 'Check', true);
    $switch = $main->toggle('s');
    $press = $main->toggleButton('t', 'Press');

    expect($check->native()->state())->toBe(NSControlStateValue::ON)
        ->and($check->native()->title())->toBe('Check')
        ->and($switch->native())->toBeInstanceOf(NSSwitch::class)
        ->and($switch->native()->state())->toBe(NSControlStateValue::OFF)
        ->and($press->native()->state())->toBe(NSControlStateValue::OFF);

    foreach ([$check, $switch, $press] as $control) {
        $control->native()->performClick(null);
    }
    expect(takeMail(session()))->toEqual([
        new Toggled('main', 'm.c', $check->uuid(), false),
        new Toggled('main', 'm.s', $switch->uuid(), true),
        new Toggled('main', 'm.t', $press->uuid(), true),
    ])->and($check->isChecked())->toBeFalse()
        ->and($switch->isOn())->toBeTrue()
        ->and($press->isPressed())->toBeTrue();

    $check->setChecked(true)->setLabel('Again');
    $switch->setOn(false);
    $press->setPressed(false)->setLabel('Pushed');
    expect(takeMail(session()))->toBe([])
        ->and($check->native()->state())->toBe(NSControlStateValue::ON)
        ->and($check->native()->title())->toBe('Again')
        ->and($switch->native()->state())->toBe(NSControlStateValue::OFF)
        ->and($press->native()->state())->toBe(NSControlStateValue::OFF)
        ->and($press->native()->title())->toBe('Pushed');
});

it('reports a slider move as ValueChanged and writes value and range to the native', function (): void {
    $window = driver()->open('main', 300, 200);
    $slider = $window->column('m')->slider('v', 0.0, 10.0, 5.0);
    $native = $slider->native();

    expect($native->minValue())->toBe(0.0)
        ->and($native->maxValue())->toBe(10.0)
        ->and($native->doubleValue())->toBe(5.0)
        ->and($native->isContinuous())->toBeTrue();

    $native->setDoubleValue(7.5);
    $native->sendActionTo($native->action(), $native->target());
    expect(takeMail(session()))->toEqual([new ValueChanged('main', 'm.v', $slider->uuid(), 7.5)])
        ->and($slider->value())->toBe(7.5);

    $slider->setRange(0.0, 6.0);
    expect($native->maxValue())->toBe(6.0)
        ->and($native->doubleValue())->toBe(6.0)
        ->and($slider->value())->toBe(6.0)
        ->and(takeMail(session()))->toBe([]);
});

it('lists options with duplicates, reports a choice as SelectionChanged, and follows setOptions', function (): void {
    $window = driver()->open('main', 300, 200);
    $dropdown = $window->column('m')->dropdown('d', ['a', 'a', 'b'], 1);
    $native = $dropdown->native();

    expect($native->itemTitles())->toBe(['a', 'a', 'b'])
        ->and($native->indexOfSelectedItem())->toBe(1);

    $native->selectItemAtIndex(2);
    $native->sendActionTo($native->action(), $native->target());
    expect(takeMail(session()))->toEqual([new SelectionChanged('main', 'm.d', $dropdown->uuid(), 2, 'b')])
        ->and($dropdown->selected())->toBe(2);

    $dropdown->setOptions(['x', 'y']);
    expect($native->itemTitles())->toBe(['x', 'y'])
        ->and($native->indexOfSelectedItem())->toBe(0);
    $dropdown->setOptions([]);
    expect($native->numberOfItems())->toBe(0)
        ->and($dropdown->selected())->toBe(-1);
});

it('reports a picked day as DateChanged in PHP\'s time zone and shows the date set from code', function (): void {
    // Far from UTC, where a day read in the wrong zone comes back one off.
    $previous = date_default_timezone_get();
    date_default_timezone_set('Pacific/Auckland');
    try {
        $window = driver()->open('main', 300, 200);
        $zone = new DateTimeZone('Pacific/Auckland');
        $picker = $window->column('m')->datepicker('d', new DateTimeImmutable('2026-03-14', $zone));
        $native = $picker->native();
        $now = new DateTimeImmutable();

        expect((new DateTimeZone($native->timeZone()->name()))->getOffset($now))->toBe($zone->getOffset($now))
            ->and((new DateTimeImmutable('@'.(int) $native->dateValue()->timeIntervalSince1970()))->setTimezone($zone)->format('Y-m-d'))->toBe('2026-03-14');

        $native->setDateValue(NSDate::dateWithTimeIntervalSince1970((float) (new DateTimeImmutable('2026-07-04 15:30', $zone))->getTimestamp()));
        $native->sendActionTo($native->action(), $native->target());
        $mail = takeMail(session());

        expect($mail)->toHaveCount(1)
            ->and($mail[0])->toBeInstanceOf(DateChanged::class)
            ->and($mail[0]->date->format('Y-m-d H:i'))->toBe('2026-07-04 00:00')
            ->and($mail[0]->date->getTimezone()->getName())->toBe($zone->getName())
            ->and($picker->date()->format('Y-m-d'))->toBe('2026-07-04');
    } finally {
        date_default_timezone_set($previous);
    }
});

it('paints a text input and a text area background on the control itself', function (): void {
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $input = $main->textInput('in', 'x');
    $area = $main->textArea('area', 'y')->fill();
    $builtInput = [$input->native()->backgroundColor(), $input->native()->drawsBackground()];
    $text = $area->native()->documentView();
    $builtArea = [$text->backgroundColor(), $text->drawsBackground()];

    $input->setBackground(Color::hex('#00ff00'));
    $area->setBackground(Color::hex('#0000ff'));
    expect(round($input->native()->backgroundColor()->greenComponent(), 2))->toBe(1.0)
        ->and($input->native()->drawsBackground())->toBeTrue()
        ->and(round($text->backgroundColor()->blueComponent(), 2))->toBe(1.0)
        ->and($text->drawsBackground())->toBeTrue();

    $input->setBackground(null);
    $area->setBackground(null);
    expect([$input->native()->backgroundColor(), $input->native()->drawsBackground()])->toEqual($builtInput)
        ->and([$text->backgroundColor(), $text->drawsBackground()])->toEqual($builtArea);
});

it('shows a text area at the height of its text when it does not fill', function (): void {
    $window = driver()->open('main', 300, 300);
    $area = $window->column('m')->textArea('notes', "one\ntwo\nthree");
    $window->present();
    pumpFor(0.2);

    expect($area->size()[1])->toBeGreaterThan(30);
});
